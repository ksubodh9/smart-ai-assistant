<?php

namespace Subodh\SmartAiAssistant\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Subodh\SmartAiAssistant\Core\Contracts\ConversationStore;
use Subodh\SmartAiAssistant\Core\Contracts\EscalationChannel;
use Subodh\SmartAiAssistant\Core\Contracts\Interpreter;
use Subodh\SmartAiAssistant\Core\Contracts\UserContextResolver;
use Subodh\SmartAiAssistant\Core\Data\EscalationRequest;
use Subodh\SmartAiAssistant\Core\Data\EscalationResult;
use Subodh\SmartAiAssistant\Core\Data\IncomingMessage;
use Subodh\SmartAiAssistant\Support\Locales;
use Subodh\SmartAiAssistant\Support\ResponseCatalog;

class EscalationController extends Controller
{
    private const HTTP_STATUS = [
        EscalationResult::CREATED   => 201,
        EscalationResult::REJECTED  => 422,
        EscalationResult::THROTTLED => 429,
        EscalationResult::FAILED    => 503,
    ];

    /**
     * Hand the user's request to the configured escalation channel.
     *
     * Expected multipart payload:
     *   - message (string, required unless attachments are sent)
     *   - error_context (string, optional): page error the user picked
     *   - page_url (string, optional)
     *   - attachments[] (files, optional)
     *   - conversation_id (int, optional): the chat the request comes from
     *
     * Identity comes from the UserContextResolver only; identity fields in the
     * payload are ignored.
     */
    public function store(
        Request $request,
        UserContextResolver $users,
        Interpreter $interpreter,
        EscalationChannel $channel,
        ConversationStore $conversations,
        ResponseCatalog $responses,
        Locales $locales,
    ) {
        $user = $users->resolve($request);

        if (! $user->isAuthenticated()) {
            return response()->json([
                'status'  => EscalationResult::REJECTED,
                'message' => $locales->pick($responses->answers('escalation_login_required'), $locales->default())['text'] ?? '',
            ], 401);
        }

        $config = config('smart-ai-assistant.escalation', []);
        $attachments = $config['attachments'] ?? [];

        $validated = $request->validate([
            'message'       => 'nullable|string|max:' . (int) ($config['max_message_length'] ?? 1000) . '|required_without:attachments',
            'error_context' => 'nullable|string|max:1000',
            'page_url'      => 'nullable|string|max:2048',
            'conversation_id' => 'nullable|integer',
            'attachments'   =>'nullable|array|max:' . (int) ($attachments['max_files'] ?? 2),
            'attachments.*' => 'file|mimes:' . implode(',', $attachments['mimes'] ?? ['jpg', 'jpeg', 'png', 'pdf'])
                . '|max:' . (int) ($attachments['max_kb'] ?? 2048),
        ]);

        $message = trim($validated['message'] ?? '');
        $errorContext = isset($validated['error_context']) ? (trim($validated['error_context']) ?: null) : null;

        // The path only: query strings can carry transaction ids and personal data
        $pageUrl = isset($validated['page_url'])
            ? (parse_url($validated['page_url'], PHP_URL_PATH) ?: null)
            : null;

        // Domain tags help the host route the request (e.g. to a team)
        $problem = $interpreter->interpret(new IncomingMessage(
            trim(($errorContext ?? '') . ' ' . $message),
            IncomingMessage::SOURCE_TYPED,
            $pageUrl,
        ));

        $conversationId = $conversations->open(
            isset($validated['conversation_id']) ? (int) $validated['conversation_id'] : null,
            $user,
            $request->hasSession() ? $request->session()->getId() : null,
            $pageUrl,
        );

        $escalation = new EscalationRequest(
            user: $user,
            message: $message,
            errorContext: $errorContext,
            pageUrl: $pageUrl,
            attachments: array_values($request->file('attachments', [])),
            domains: $problem->domains,
            conversationId: $conversationId,
        );

        $result = $channel->escalate($escalation);
        $conversations->recordEscalation($conversationId, $escalation, $result);

        return response()->json([
            'conversation_id' => $conversationId,
            'status'    => $result->status,
            'message'   => $result->message,
            'reference' => $result->reference,
            'view_url'  => $result->viewUrl,
        ], self::HTTP_STATUS[$result->status] ?? 500);
    }
}
