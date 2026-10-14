<?php

namespace Subodh\SmartAiAssistant\Strategies;

use Subodh\SmartAiAssistant\Answering\AnswerCallLimiter;
use Subodh\SmartAiAssistant\Core\Contracts\AnswerGenerator;
use Subodh\SmartAiAssistant\Core\Contracts\PassageSource;
use Subodh\SmartAiAssistant\Core\Contracts\Redactor;
use Subodh\SmartAiAssistant\Core\Contracts\ResolutionStrategy;
use Subodh\SmartAiAssistant\Core\Data\ConversationContext;
use Subodh\SmartAiAssistant\Core\Data\Passage;
use Subodh\SmartAiAssistant\Core\Data\Resolution;
use Subodh\SmartAiAssistant\Core\Data\StructuredProblem;
use Subodh\SmartAiAssistant\Models\Document;
use Subodh\SmartAiAssistant\Support\Locales;
use Subodh\SmartAiAssistant\Support\ResponseCatalog;
use Throwable;

/**
 * Answer from the host's documents: retrieve passages, then let the
 * AnswerGenerator write an answer grounded in them. Runs after the knowledge
 * base and before the fallback, only with the 'document_answers' capability.
 *
 * Whenever this cannot give a grounded answer it returns null, so the user
 * gets the unchanged fallback reply with "Raise ticket": no passage scores
 * above documents.retrieval.min_score (then no LLM call is made), a daily
 * call cap is reached, the generator fails, times out or says the passages
 * do not answer the question. Only the redacted question and the passages
 * leave the application.
 */
class DocumentAnswerStrategy implements ResolutionStrategy
{
    public function __construct(
        private readonly PassageSource $passages,
        private readonly AnswerGenerator $generator,
        private readonly AnswerCallLimiter $limiter,
        private readonly Redactor $redactor,
        private readonly ResponseCatalog $responses,
        private readonly Locales $locales,
    ) {
    }

    public function resolve(StructuredProblem $problem, ConversationContext $context): ?Resolution
    {
        $user = $context->user;
        $settings = (array) config('smart-ai-assistant.documents.answers', []);

        if ($problem->intent !== StructuredProblem::INTENT_REPORT_ERROR
            || (!$user->isAuthenticated() && empty($settings['guests']))) {
            return null;
        }

        $passages = $this->passages->search(
            $context->message->text,
            $user->isAuthenticated() ? Document::AUDIENCES : [Document::AUDIENCE_PUBLIC],
            max(1, (int) ($settings['passages'] ?? 3)),
        );

        if ($passages === [] || !$this->limiter->attempt($user->id)) {
            return null;
        }

        try {
            $answer = $this->generator->generate(
                $this->redactor->redact($context->message->text),
                $passages,
                $this->locales->label($context->locale) . " ({$context->locale})",
            );
        } catch (Throwable $e) {
            report($e);
            return null;
        }

        if ($answer === null || !$answer->answerable || trim($answer->text) === '') {
            return null;
        }

        $used = array_values(array_filter($passages, fn (Passage $p) => in_array($p->id, $answer->sourceIds, true))) ?: $passages;

        return new Resolution(
            outcome: Resolution::ANSWERED,
            source: 'document',
            answers: [$context->locale => $this->withSources(trim($answer->text), $used, $context->locale, $settings)],
            persist: true,
            provenance: [
                'strategy'  => 'document_answer',
                'documents' => $this->documents($used),
                'passages'  => array_map(fn (Passage $p) => $p->id, $used),
            ],
        );
    }

    public function capabilities(): array
    {
        return ['document_answers'];
    }

    /**
     * @param  list<Passage>  $used
     */
    private function withSources(string $text, array $used, string $locale, array $settings): string
    {
        if (!($settings['show_sources'] ?? true)) {
            return $text;
        }

        $titles = implode(', ', array_unique(array_map(fn (Passage $p) => $p->title, $used)));
        $line = $this->locales->pick($this->responses->answers('document_sources', [':titles' => $titles]), $locale)['text'] ?? null;

        return $line !== null ? "{$text}\n\n{$line}" : $text;
    }

    /**
     * Which document versions the answer came from, for the stored conversation.
     *
     * @param  list<Passage>  $used
     * @return list<array{id: int, title: string, version: int}>
     */
    private function documents(array $used): array
    {
        $documents = [];

        foreach ($used as $passage) {
            $documents[$passage->documentId] = ['id' => $passage->documentId, 'title' => $passage->title, 'version' => $passage->version];
        }

        return array_values($documents);
    }
}
