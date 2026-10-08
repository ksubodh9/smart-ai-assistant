<?php

namespace Subodh\SmartAiAssistant\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Subodh\SmartAiAssistant\Core\Data\IncomingMessage;
use Subodh\SmartAiAssistant\Http\MessageResponder;

/**
 * POST /smart-assistant/message: anything the user sends the assistant, typed
 * or picked. Nothing here creates a ticket; an unresolved reply offers the
 * "escalate" action, which the widget sends to /escalate once the user confirms.
 */
class MessageController extends Controller
{
    private const SOURCES = [
        IncomingMessage::SOURCE_TYPED,
        IncomingMessage::SOURCE_PAGE_ERROR,
        IncomingMessage::SOURCE_SUGGESTION,
    ];

    /**
     * Expected payload:
     *   - text (string, required)
     *   - source (typed|page_error|suggestion, optional, default typed)
     *   - page_url (string, optional)
     *   - conversation_id (int, optional): from the previous response; only
     *     honoured for the same user (or guest session)
     *   - locale (string, optional): the reply language picked in the widget;
     *     'auto', missing or unknown codes follow the language of the message
     */
    public function store(Request $request, MessageResponder $responder)
    {
        $validated = $request->validate([
            'text'            => 'required|string|max:1000',
            'source'          => 'nullable|in:' . implode(',', self::SOURCES),
            'page_url'        => 'nullable|string|max:2048',
            'conversation_id' => 'nullable|integer',
            'locale'          => 'nullable|string|max:20',
        ]);

        return response()->json($responder->respond(
            $request,
            $validated['text'],
            $validated['source'] ?? IncomingMessage::SOURCE_TYPED,
            $validated['page_url'] ?? null,
            isset($validated['conversation_id']) ? (int) $validated['conversation_id'] : null,
            $validated['locale'] ?? null,
        ));
    }
}
