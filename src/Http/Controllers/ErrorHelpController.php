<?php

namespace Subodh\SmartAiAssistant\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Subodh\SmartAiAssistant\Core\Data\IncomingMessage;
use Subodh\SmartAiAssistant\Http\MessageResponder;

/**
 * POST /smart-assistant/help: a page error the user picked. Kept for widgets
 * released before /message; same pipeline and response.
 */
class ErrorHelpController extends Controller
{
    /**
     * Expected payload:
     *   - error_text (string, required)
     *   - page_url (string, optional)
     *   - conversation_id (int, optional): from the previous response; only
     *     honoured for the same user (or guest session)
     *   - locale (string, optional): as for /message
     *
     * The responder is method-injected, not constructor-injected: the router
     * reuses controller instances, and it depends on per-request config.
     */
    public function store(Request $request, MessageResponder $responder)
    {
        $validated = $request->validate([
            'error_text'      => 'required|string|max:1000',
            'page_url'        => 'nullable|string|max:2048',
            'conversation_id' => 'nullable|integer',
            'locale'          => 'nullable|string|max:20',
        ]);

        return response()->json($responder->respond(
            $request,
            $validated['error_text'],
            IncomingMessage::SOURCE_PAGE_ERROR,
            $validated['page_url'] ?? null,
            isset($validated['conversation_id']) ? (int) $validated['conversation_id'] : null,
            $validated['locale'] ?? null,
        ));
    }
}
