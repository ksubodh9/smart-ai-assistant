<?php

namespace Subodh\SmartAiAssistant\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Subodh\SmartAiAssistant\Core\Contracts\Interpreter;
use Subodh\SmartAiAssistant\Core\Contracts\KnowledgeSource;
use Subodh\SmartAiAssistant\Core\Contracts\UserContextResolver;
use Subodh\SmartAiAssistant\Core\Data\IncomingMessage;
use Subodh\SmartAiAssistant\Core\Data\StructuredProblem;
use Subodh\SmartAiAssistant\Models\Conversation;
use Subodh\SmartAiAssistant\Models\Message;
use Subodh\SmartAiAssistant\Support\InputClassifier;

class ErrorHelpController extends Controller
{
    /**
     * Intents that get a canned reply and are not stored.
     */
    private const NON_PROCESSABLE = [
        StructuredProblem::INTENT_EMPTY,
        StructuredProblem::INTENT_ABUSE,
        StructuredProblem::INTENT_NOISE,
        StructuredProblem::INTENT_GREETING,
        StructuredProblem::INTENT_VAGUE,
    ];

    /**
     * Handle the incoming help request.
     *
     * Expected payload:
     *   - error_text (string, required)
     *   - page_url (string, optional)
     *
     * Dependencies are method-injected, not constructor-injected: the router
     * reuses controller instances, and these depend on per-request config.
     */
    public function store(
        Request $request,
        UserContextResolver $userContextResolver,
        Interpreter $interpreter,
        KnowledgeSource $knowledge,
        InputClassifier $inputClassifier,
    ) {
        $user = $userContextResolver->resolve($request);

        $validated = $request->validate([
            'error_text' => 'required|string|max:1000',
            'page_url'   => 'nullable|string|max:2048',
        ]);

        $errorText = trim($validated['error_text']);
        // Store the path only: query strings can carry transaction ids and personal data
        $pageUrl   = isset($validated['page_url'])
            ? (parse_url($validated['page_url'], PHP_URL_PATH) ?: null)
            : null;
        $service   = config('smart-ai-assistant.default_service', 'AEPS');

        // =====================================================================
        // STEP 1: Interpretation (Deterministic)
        // =====================================================================
        $message   = new IncomingMessage($errorText, IncomingMessage::SOURCE_PAGE_ERROR, $pageUrl);
        $problem   = $interpreter->interpret($message);
        $inputType = $problem->signals['input_type'];
        $category  = $problem->domains[0] ?? null;

        // =====================================================================
        // STEP 2: Handle non-processable input (no logging for noise)
        // =====================================================================
        if (in_array($problem->intent, self::NON_PROCESSABLE, true)) {
            // Check for response loop - don't repeat the same guidance
            $lastResponse = session('smart_assistant_last_response');
            $currentResponse = $inputClassifier->cannedResponse($inputType);
            
            if ($lastResponse === $currentResponse) {
                // Exit message to prevent loop
                $exitMessage = "I've shared all available guidance for this issue.\nPlease contact support if further assistance is required.";
                return response()->json([
                    'conversation_id' => null,
                    'source'          => 'exit',
                    'answer_en'       => $exitMessage,
                    'answer_hi'       => null,
                    'input_type'      => 'loop_exit',
                ]);
            }
            
            // Store current response in session
            session(['smart_assistant_last_response' => $currentResponse]);
            
            return response()->json([
                'conversation_id' => null,
                'source'          => $inputType,
                'answer_en'       => $currentResponse,
                'answer_hi'       => null,
                'input_type'      => $inputType,
            ]);
        }

        // =====================================================================
        // STEP 3: Handle explicit escalation request
        // =====================================================================
        if ($problem->intent === StructuredProblem::INTENT_REQUEST_HUMAN) {
            $escalationMessage = "Your request has been noted. Please use the 'Raise Ticket' option to connect with our support team, or call our helpline for immediate assistance.";
            
            return response()->json([
                'conversation_id' => null,
                'source'          => 'escalation',
                'answer_en'       => $escalationMessage,
                'answer_hi'       => "आपका अनुरोध दर्ज किया गया है। कृपया 'टिकट बनाएं' विकल्प का उपयोग करें या तुरंत सहायता के लिए हमारी हेल्पलाइन पर कॉल करें।",
                'input_type'      => $inputType,
            ]);
        }

        // =====================================================================
        // STEP 4: KB Matching (for valid input)
        // =====================================================================
        $entry = $knowledge->find($message, $problem)[0] ?? null;

        // Build response
        if ($entry) {
            $prefix = $category 
                ? "I understand you are facing a **{$category}** issue.\n\n" 
                : "";

            $answerEn = $prefix . $entry->content['en'];
            $answerHi = $entry->content['hi'] ?? '';
            $source   = 'kb';
            $matchedId = $entry->id;
        } else {
            // No KB match - check if input is meaningful enough to escalate
            $prefix = $category 
                ? "I understand you are facing a **{$category}** issue, but " 
                : "";

            $answerEn = $prefix . "this specific error is not yet documented.\n\nIf this issue is urgent, please use the 'Raise Ticket' option to contact support.";
            $answerHi = "यह त्रुटि अभी दस्तावेज़ में नहीं है। कृपया 'टिकट बनाएं' विकल्प का उपयोग करें।";
            $source   = 'unknown';
            $matchedId = null;
        }

        // =====================================================================
        // STEP 5: Response Loop Prevention
        // =====================================================================
        $responseHash = md5($answerEn);
        $lastResponseHash = session('smart_assistant_last_response_hash');
        
        if ($lastResponseHash === $responseHash) {
            // Same response about to be sent - exit cleanly
            $exitMessage = "I've shared all available guidance for this issue.\nPlease contact support if further assistance is required.";
            session()->forget('smart_assistant_last_response_hash');
            
            return response()->json([
                'conversation_id' => null,
                'source'          => 'exit',
                'answer_en'       => $exitMessage,
                'answer_hi'       => null,
                'input_type'      => 'loop_exit',
            ]);
        }
        
        session(['smart_assistant_last_response_hash' => $responseHash]);

        // =====================================================================
        // STEP 6: Create conversation record (only for meaningful input)
        // =====================================================================
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'service' => $service,
            'status'  => 'resolved',
            'page_url'=> $pageUrl,
            'meta'    => [
                'raw_error_text' => $errorText,
                'input_type'     => $inputType,
                'category'       => $category,
            ],
        ]);

        // Store the user's message
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type'    => 'user',
            'message'        => $errorText,
            'data'           => [
                'input_type' => $inputType,
                'category'   => $category,
            ],
        ]);

        // Store AI/system response message
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type'    => 'ai',
            'message'        => $answerEn . "\n" . $answerHi,
            'data'           => [
                'source' => $source,
                'input_type' => $inputType,
                'category' => $category,
                'matched_error_id' => $matchedId,
            ],
        ]);

        return response()->json([
            'conversation_id' => $conversation->id,
            'source'          => $source,
            'answer_en'       => $answerEn,
            'answer_hi'       => $answerHi,
            'input_type'      => $inputType,
            'category'        => $category,
        ]);
    }
}

