<?php

namespace Subodh\SmartAiAssistant\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Subodh\SmartAiAssistant\Core\Contracts\ConversationStore;
use Subodh\SmartAiAssistant\Core\Contracts\Interpreter;
use Subodh\SmartAiAssistant\Core\Contracts\UserContextResolver;
use Subodh\SmartAiAssistant\Core\Data\ConversationContext;
use Subodh\SmartAiAssistant\Core\Data\IncomingMessage;
use Subodh\SmartAiAssistant\Core\Resolution\ResolverPipeline;
use Subodh\SmartAiAssistant\Http\ResponseSerializer;

class ErrorHelpController extends Controller
{
    /**
     * Handle the incoming help request.
     *
     * Expected payload:
     *   - error_text (string, required)
     *   - page_url (string, optional)
     *   - conversation_id (int, optional): from the previous response; only
     *     honoured for the same user (or guest session)
     *
     * Dependencies are method-injected, not constructor-injected: the router
     * reuses controller instances, and these depend on per-request config.
     */
    public function store(
        Request $request,
        UserContextResolver $users,
        Interpreter $interpreter,
        ResolverPipeline $pipeline,
        ConversationStore $conversations,
        ResponseSerializer $serializer,
    ) {
        $user = $users->resolve($request);

        $validated = $request->validate([
            'error_text'      => 'required|string|max:1000',
            'page_url'        => 'nullable|string|max:2048',
            'conversation_id' => 'nullable|integer',
        ]);

        // Store the path only: query strings can carry transaction ids and personal data
        $pageUrl = isset($validated['page_url'])
            ? (parse_url($validated['page_url'], PHP_URL_PATH) ?: null)
            : null;

        $conversationId = $conversations->open(
            isset($validated['conversation_id']) ? (int) $validated['conversation_id'] : null,
            $user,
            $request->hasSession() ? $request->session()->getId() : null,
            $pageUrl,
        );

        $message = new IncomingMessage(trim($validated['error_text']), IncomingMessage::SOURCE_PAGE_ERROR, $pageUrl);
        $context = new ConversationContext($user, $message, $conversations->state($conversationId));

        $problem = $interpreter->interpret($message);
        $resolution = $pipeline->resolve($problem, $context);

        // Only meaningful exchanges are stored (not canned replies, escalation or exits)
        if ($resolution->persist) {
            $conversations->record($conversationId, $context, $problem, $resolution);
        }

        return response()->json($serializer->toArray($resolution, $problem, $conversationId));
    }
}
