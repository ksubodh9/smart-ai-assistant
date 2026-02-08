<?php

namespace Subodh\SmartAiAssistant\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Subodh\SmartAiAssistant\Models\Conversation;
use Subodh\SmartAiAssistant\Models\Message;
use Cartalyst\Sentinel\Laravel\Facades\Sentinel;
use Subodh\SmartAiAssistant\Support\ErrorMatcher;
use Subodh\SmartAiAssistant\Support\InputClassifier;

class ErrorHelpController extends Controller
{
    protected InputClassifier $inputClassifier;

    public function __construct()
    {
        $this->inputClassifier = new InputClassifier();
    }

    /**
     * Handle the incoming help request.
     *
     * Expected payload:
     *   - error_text (string, required)
     *   - page_url (string, optional)
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'error_text' => 'required|string',
            'page_url'   => 'nullable|string',
        ]);
        
        $errorText = trim($validated['error_text']);
        $pageUrl   = $validated['page_url'] ?? null;
        $service   = config('smart-ai-assistant.default_service', 'AEPS');

        // =====================================================================
        // STEP 1: Input Classification (Deterministic)
        // =====================================================================
        $classification = $this->inputClassifier->classify($errorText);

        // =====================================================================
        // STEP 2: Handle non-processable input (no logging for noise)
        // =====================================================================
        if (!$classification['should_process']) {
            // Check for response loop - don't repeat the same guidance
            $lastResponse = session('smart_assistant_last_response');
            $currentResponse = $classification['response'];
            
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
                'source'          => $classification['type'],
                'answer_en'       => $currentResponse,
                'answer_hi'       => null,
                'input_type'      => $classification['type'],
            ]);
        }

        // =====================================================================
        // STEP 3: Handle explicit escalation request
        // =====================================================================
        if ($classification['should_escalate'] ?? false) {
            $escalationMessage = "Your request has been noted. Please use the 'Raise Ticket' option to connect with our support team, or call our helpline for immediate assistance.";
            
            return response()->json([
                'conversation_id' => null,
                'source'          => 'escalation',
                'answer_en'       => $escalationMessage,
                'answer_hi'       => "आपका अनुरोध दर्ज किया गया है। कृपया 'टिकट बनाएं' विकल्प का उपयोग करें या तुरंत सहायता के लिए हमारी हेल्पलाइन पर कॉल करें।",
                'input_type'      => $classification['type'],
            ]);
        }

        // =====================================================================
        // STEP 4: KB Matching (for valid input)
        // =====================================================================
        $errorMatcher = app(ErrorMatcher::class);
        $definition = $errorMatcher->findMatchingDefinition($service, $errorText);

        // Build response
        if ($definition) {
            $prefix = $classification['category'] 
                ? "I understand you are facing a **{$classification['category']}** issue.\n\n" 
                : "";

            $answerEn = $prefix . $definition->answer_en;
            $answerHi = $definition->answer_hi ?? '';
            $source   = 'kb';
            $matchedId = $definition->id;
        } else {
            // No KB match - check if input is meaningful enough to escalate
            $prefix = $classification['category'] 
                ? "I understand you are facing a **{$classification['category']}** issue, but " 
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
            'user_id' => Sentinel::check() ? Sentinel::getUser()->id : null,
            'service' => $service,
            'status'  => 'resolved',
            'page_url'=> $pageUrl,
            'meta'    => [
                'raw_error_text' => $errorText,
                'input_type'     => $classification['type'],
                'category'       => $classification['category'],
            ],
        ]);

        // Store the user's message
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type'    => 'user',
            'message'        => $errorText,
            'data'           => [
                'input_type' => $classification['type'],
                'category'   => $classification['category'],
            ],
        ]);

        // Store AI/system response message
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type'    => 'ai',
            'message'        => $answerEn . "\n" . $answerHi,
            'data'           => [
                'source' => $source,
                'input_type' => $classification['type'],
                'category' => $classification['category'],
                'matched_error_id' => $matchedId,
            ],
        ]);

        return response()->json([
            'conversation_id' => $conversation->id,
            'source'          => $source,
            'answer_en'       => $answerEn,
            'answer_hi'       => $answerHi,
            'input_type'      => $classification['type'],
            'category'        => $classification['category'],
        ]);
    }
}

