<?php

namespace Subodh\SmartAiAssistant\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Subodh\SmartAiAssistant\Models\ErrorDefinition;
use Subodh\SmartAiAssistant\Models\Conversation;
use Subodh\SmartAiAssistant\Models\Message;
use Cartalyst\Sentinel\Laravel\Facades\Sentinel;
use Subodh\SmartAiAssistant\Support\ErrorMatcher;

class ErrorHelpController extends Controller
{
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

        // Use ErrorMatcher for substring-based KB matching
        $errorMatcher = app(ErrorMatcher::class);
        $definition = $errorMatcher->findMatchingDefinition($service, $errorText);
        // Create a conversation record
        $conversation = Conversation::create([
            'user_id' => Sentinel::check() ? Sentinel::getUser()->id : null,
            'service' => $service,
            'status'  => 'resolved', // V1 always resolves with a canned answer
            'page_url'=> $pageUrl,
            'meta'    => ['raw_error_text' => $errorText],
        ]);

        // Store the user's message
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type'    => 'user',
            'message'        => $errorText,
            'data'           => [],
        ]);

        if ($definition) {
            $answerEn = $definition->answer_en;
            $answerHi = $definition->answer_hi ?? '';
            $source   = 'kb';
            $matchedId = $definition->id;
        } else {
            // Generic fallback – can be replaced with real AI later
            $answerEn = "We received this error, it's not yet documented. Please contact support or forward this to a human agent.";
            $answerHi = "हमें यह त्रुटि प्राप्त हुई है, यह अभी दस्तावेज़ में नहीं है। कृपया समर्थन से संपर्क करें या इसे मानव एजेंट को अग्रेषित करें।";
            $source   = 'unknown';
            $matchedId = null;
        }

        // Store AI/system response message
        $aiMessage = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type'    => 'ai',
            'message'        => $answerEn . "\n" . $answerHi,
            'data'           => [
                'source' => $source,
                'matched_error_id' => $matchedId,
            ],
        ]);

        return response()->json([
            'conversation_id' => $conversation->id,
            'source'          => $source,
            'answer_en'       => $answerEn,
            'answer_hi'       => $answerHi,
        ]);
    }
}
