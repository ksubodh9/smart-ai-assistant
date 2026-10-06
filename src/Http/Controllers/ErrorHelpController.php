<?php

namespace Subodh\SmartAiAssistant\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Subodh\SmartAiAssistant\Core\Contracts\ConversationState;
use Subodh\SmartAiAssistant\Core\Contracts\Interpreter;
use Subodh\SmartAiAssistant\Core\Contracts\UserContextResolver;
use Subodh\SmartAiAssistant\Core\Data\ConversationContext;
use Subodh\SmartAiAssistant\Core\Data\IncomingMessage;
use Subodh\SmartAiAssistant\Core\Resolution\ResolverPipeline;
use Subodh\SmartAiAssistant\Http\ResponseSerializer;
use Subodh\SmartAiAssistant\Persistence\EloquentConversationStore;

class ErrorHelpController extends Controller
{
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
        UserContextResolver $users,
        Interpreter $interpreter,
        ResolverPipeline $pipeline,
        ConversationState $state,
        EloquentConversationStore $conversations,
        ResponseSerializer $serializer,
    ) {
        $user = $users->resolve($request);

        $validated = $request->validate([
            'error_text' => 'required|string|max:1000',
            'page_url'   => 'nullable|string|max:2048',
        ]);

        // Store the path only: query strings can carry transaction ids and personal data
        $pageUrl = isset($validated['page_url'])
            ? (parse_url($validated['page_url'], PHP_URL_PATH) ?: null)
            : null;

        $message = new IncomingMessage(trim($validated['error_text']), IncomingMessage::SOURCE_PAGE_ERROR, $pageUrl);
        $context = new ConversationContext($user, $message, $state);

        $problem = $interpreter->interpret($message);
        $resolution = $pipeline->resolve($problem, $context);

        // Only meaningful exchanges are stored (not canned replies, escalation or exits)
        $conversationId = $resolution->persist
            ? $conversations->record($context, $problem, $resolution)
            : null;

        return response()->json($serializer->toArray($resolution, $problem, $conversationId));
    }
}
