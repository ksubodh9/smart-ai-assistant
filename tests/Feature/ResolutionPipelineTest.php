<?php

namespace Subodh\SmartAiAssistant\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Subodh\SmartAiAssistant\Core\Contracts\ConversationState;
use Subodh\SmartAiAssistant\Core\Contracts\ResolutionStrategy;
use Subodh\SmartAiAssistant\Core\Data\ConversationContext;
use Subodh\SmartAiAssistant\Core\Data\IncomingMessage;
use Subodh\SmartAiAssistant\Core\Data\Resolution;
use Subodh\SmartAiAssistant\Core\Data\StructuredProblem;
use Subodh\SmartAiAssistant\Core\Data\UserContext;
use Subodh\SmartAiAssistant\Core\Resolution\ResolverPipeline;
use Subodh\SmartAiAssistant\Models\ErrorDefinition;
use Subodh\SmartAiAssistant\Strategies\FallbackStrategy;
use Subodh\SmartAiAssistant\Strategies\InputGuardStrategy;
use Subodh\SmartAiAssistant\Strategies\KnowledgeLookupStrategy;
use Subodh\SmartAiAssistant\Tests\TestCase;

/**
 * The resolver is configured, not coded: strategy order, capabilities and
 * host strategies all come from config.
 */
class ResolutionPipelineTest extends TestCase
{
    use RefreshDatabase;

    private function ask(string $text)
    {
        return $this->withCredentials()
            ->withCookie(config('session.cookie'), Str::random(40))
            ->postJson('/smart-assistant/help', ['error_text' => $text]);
    }

    private function context(string $text): ConversationContext
    {
        return new ConversationContext(UserContext::guest(), new IncomingMessage($text), app(ConversationState::class));
    }

    public function test_disabling_the_knowledge_capability_skips_the_kb(): void
    {
        ErrorDefinition::create(['service' => 'AEPS', 'key_text' => 'capture timeout', 'answer_en' => 'Clean the scanner.']);
        config(['smart-ai-assistant.capabilities.knowledge' => false]);

        $this->ask('capture timeout')->assertJson(['source' => 'unknown']);
    }

    public function test_disabling_escalation_sends_requests_for_a_human_to_the_fallback(): void
    {
        config(['smart-ai-assistant.capabilities.escalation' => false]);

        $this->ask('talk to a human')->assertJson(['source' => 'unknown', 'input_type' => 'escalation_request']);
    }

    public function test_a_host_strategy_can_answer_before_the_built_in_ones(): void
    {
        config(['smart-ai-assistant.resolution.strategies' => [
            InputGuardStrategy::class,
            OrderStatusStrategy::class,
            KnowledgeLookupStrategy::class,
            FallbackStrategy::class,
        ]]);

        $this->ask('where is my order')->assertJson([
            'source'    => 'order_status',
            'answer_en' => 'Your order ships tomorrow.',
        ]);
        $this->ask('aeps withdrawal failed')->assertJson(['source' => 'unknown']);
    }

    public function test_a_configured_class_must_be_a_strategy(): void
    {
        config(['smart-ai-assistant.resolution.strategies' => [\stdClass::class]]);

        $this->expectException(InvalidArgumentException::class);

        app(ResolverPipeline::class);
    }

    public function test_the_pipeline_requires_a_strategy_that_resolves(): void
    {
        $pipeline = new ResolverPipeline([]);

        $this->expectException(LogicException::class);

        $pipeline->resolve(new StructuredProblem(StructuredProblem::INTENT_REPORT_ERROR), $this->context('x'));
    }

    public function test_canned_reply_for_empty_input(): void
    {
        // Unreachable over HTTP (validation rejects empty text), so checked directly
        $resolution = app(InputGuardStrategy::class)->resolve(
            new StructuredProblem(StructuredProblem::INTENT_EMPTY, signals: ['input_type' => 'empty']),
            $this->context('')
        );

        $this->assertSame(Resolution::CLARIFY, $resolution->outcome);
        $this->assertSame(['en' => 'Please type your issue message.', 'hi' => null], $resolution->answers);
    }

    public function test_host_responses_override_single_keys(): void
    {
        config(['smart-ai-assistant.responses.greeting' => ['en' => 'Hi! What went wrong?', 'hi' => 'नमस्ते!']]);

        $this->ask('hello')->assertJson(['answer_en' => 'Hi! What went wrong?', 'answer_hi' => 'नमस्ते!']);
        $this->ask('test')->assertJson(['answer_en' => 'I am ready to help. Please state your issue.']);
    }
}

class OrderStatusStrategy implements ResolutionStrategy
{
    public function resolve(StructuredProblem $problem, ConversationContext $context): ?Resolution
    {
        if (!str_contains($context->message->text, 'order')) {
            return null;
        }

        return new Resolution(Resolution::ANSWERED, 'order_status', ['en' => 'Your order ships tomorrow.', 'hi' => null], persist: true);
    }

    public function capabilities(): array
    {
        return [];
    }
}
