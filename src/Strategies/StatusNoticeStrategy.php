<?php

namespace Subodh\SmartAiAssistant\Strategies;

use Subodh\SmartAiAssistant\Core\Contracts\ResolutionStrategy;
use Subodh\SmartAiAssistant\Core\Contracts\StatusNoticeSource;
use Subodh\SmartAiAssistant\Core\Data\ConversationContext;
use Subodh\SmartAiAssistant\Core\Data\Notice;
use Subodh\SmartAiAssistant\Core\Data\Resolution;
use Subodh\SmartAiAssistant\Core\Data\StructuredProblem;
use Subodh\SmartAiAssistant\Notices\NoticeFormatter;

/**
 * A message about a service with an active known issue gets that notice
 * first, once per conversation; later messages go through the other
 * strategies as usual. Runs before the input guard so that "<service> not
 * working" (vague) and requests for a human are covered too.
 */
class StatusNoticeStrategy implements ResolutionStrategy
{
    public const STATE_KEY = 'smart_assistant_notices_shown';

    private const INTENTS = [
        StructuredProblem::INTENT_REPORT_ERROR,
        StructuredProblem::INTENT_VAGUE,
        StructuredProblem::INTENT_REQUEST_HUMAN,
    ];

    public function __construct(
        private readonly StatusNoticeSource $notices,
        private readonly NoticeFormatter $formatter,
    ) {
    }

    public function resolve(StructuredProblem $problem, ConversationContext $context): ?Resolution
    {
        if (!in_array($problem->intent, self::INTENTS, true) || $problem->domains === []) {
            return null;
        }

        $services = array_map('mb_strtolower', $problem->domains);
        $shown = (array) ($context->state->get(self::STATE_KEY) ?? []);

        $notices = array_values(array_filter(
            $this->notices->active(),
            fn (Notice $notice) => in_array(mb_strtolower($notice->service), $services, true)
                && !in_array($notice->id, $shown, true)
        ));

        if ($notices === []) {
            return null;
        }

        $ids = array_map(fn (Notice $notice) => $notice->id, $notices);
        $context->state->put(self::STATE_KEY, array_values(array_unique(array_merge($shown, $ids))));

        return new Resolution(
            outcome: Resolution::NOTICE,
            source: 'status_notice',
            answers: $this->formatter->answers($notices, 'status_notice_reply'),
            persist: true,
            provenance: ['strategy' => 'status_notice', 'notices' => $ids],
        );
    }

    public function capabilities(): array
    {
        return ['status_notices'];
    }
}
