<?php

namespace Subodh\SmartAiAssistant\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Subodh\SmartAiAssistant\Answering\AnthropicAnswerGenerator;
use Subodh\SmartAiAssistant\Answering\NullAnswerGenerator;
use Subodh\SmartAiAssistant\Core\Contracts\AnswerGenerator;
use Subodh\SmartAiAssistant\Core\Contracts\PassageSource;
use Subodh\SmartAiAssistant\Core\Data\GeneratedAnswer;
use Subodh\SmartAiAssistant\Core\Data\Passage;
use Subodh\SmartAiAssistant\Models\Conversation;
use Subodh\SmartAiAssistant\Models\Message;
use Subodh\SmartAiAssistant\Tests\TestCase;

/**
 * DocumentAnswerStrategy through /message: grounded answers only when
 * passages were found and the generator could answer; otherwise the
 * unchanged fallback with "Raise ticket".
 */
class DocumentAnswerTest extends TestCase
{
    use RefreshDatabase;

    private FakePassageSource $passages;

    private FakeAnswerGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->passages = new FakePassageSource();
        $this->generator = new FakeAnswerGenerator();
        $this->app->instance(PassageSource::class, $this->passages);
        $this->app->instance(AnswerGenerator::class, $this->generator);

        config([
            'smart-ai-assistant.capabilities.document_answers' => true,
            'smart-ai-assistant.documents.answers'             => ['passages' => 3, 'guests' => false, 'show_sources' => true],
        ]);
    }

    private function send(string $text, ?int $userId = 42)
    {
        if ($userId !== null) {
            $this->actingAs(new GenericUser(['id' => $userId, 'name' => 'U']));
        }

        return $this->postJson('/smart-assistant/message', ['text' => $text]);
    }

    private function passage(int $id, string $title = 'Refund policy', int $documentId = 7): Passage
    {
        return new Passage($id, $documentId, $title, 3, null, "Passage {$id}", 0.8);
    }

    public function test_a_logged_in_user_gets_an_answer_written_from_the_passages_with_its_source(): void
    {
        $this->passages->results = [$this->passage(1), $this->passage(2, 'Charges', 8)];
        $this->generator->answer = new GeneratedAnswer(true, 'Refunds take 5 working days.', [1]);

        $this->send('how many days for refund of failed transaction')
            ->assertOk()
            ->assertJson([
                'blocks'  => [['type' => 'text', 'text' => "Refunds take 5 working days.\n\nSource: Refund policy"]],
                'actions' => [],
                'meta'    => ['source' => 'document', 'locale' => 'en'],
            ]);

        // Logged-in users may see both audiences
        $this->assertSame([['how many days for refund of failed transaction', ['public', 'users'], 3]], $this->passages->searches);
        $this->assertSame('English (en)', $this->generator->calls[0]['language']);

        $this->assertSame('resolved', Conversation::sole()->status);
        $stored = Message::where('sender_type', 'ai')->sole();
        $this->assertSame('document', $stored->data['source']);
        $this->assertSame([['id' => 7, 'title' => 'Refund policy', 'version' => 3]], $stored->data['documents']);
        $this->assertSame([1], $stored->data['passages']);
    }

    public function test_a_second_answer_from_the_same_passages_exits_instead_of_repeating(): void
    {
        $this->passages->results = [$this->passage(1)];
        $this->generator->answer = new GeneratedAnswer(true, 'Refunds take 5 days.', [1]);

        $first = $this->send('refund days question')->assertJson(['meta' => ['source' => 'document']]);

        // Reworded by the model, but grounded in the same passage
        $this->generator->answer = new GeneratedAnswer(true, 'A refund takes five days.', [1]);
        $this->postJson('/smart-assistant/message', ['text' => 'refund days question again', 'conversation_id' => $first->json('conversation_id')])
            ->assertJson(['meta' => ['source' => 'exit'], 'actions' => [['id' => 'escalate']]]);
    }

    public function test_without_source_ids_all_passages_count_as_sources(): void
    {
        $this->passages->results = [$this->passage(1), $this->passage(2, 'Charges', 8)];
        $this->generator->answer = new GeneratedAnswer(true, 'Answer.', []);

        $this->send('refund charges question')->assertJson(['blocks' => [['text' => "Answer.\n\nSource: Refund policy, Charges"]]]);
    }

    public function test_sources_line_can_be_hidden_and_translated(): void
    {
        $this->passages->results = [$this->passage(1)];
        $this->generator->answer = new GeneratedAnswer(true, 'Answer.', [1]);
        config(['smart-ai-assistant.documents.answers.show_sources' => false]);

        $this->send('refund question one')->assertJson(['blocks' => [['text' => 'Answer.']]]);

        config([
            'smart-ai-assistant.documents.answers.show_sources' => true,
            'smart-ai-assistant.responses.document_sources'     => ['en' => 'From: :titles'],
        ]);

        $this->send('refund question two')->assertJson(['blocks' => [['text' => "Answer.\n\nFrom: Refund policy"]]]);
    }

    public function test_the_question_is_redacted_before_it_reaches_the_generator(): void
    {
        $this->passages->results = [$this->passage(1)];
        $this->generator->answer = new GeneratedAnswer(true, 'Answer.', [1]);

        $this->send('refund not received on 9876543210 please check');

        $this->assertStringNotContainsString('9876543210', $this->generator->calls[0]['question']);
        // Retrieval runs locally on the original text
        $this->assertStringContainsString('9876543210', $this->passages->searches[0][0]);
    }

    public function test_no_passages_means_no_generator_call_and_the_usual_fallback(): void
    {
        $this->send('something nobody documented')
            ->assertJson(['meta' => ['source' => 'unknown'], 'actions' => [['id' => 'escalate']]]);

        $this->assertSame([], $this->generator->calls);
    }

    public function test_unanswerable_null_or_failing_generators_fall_back(): void
    {
        $this->passages->results = [$this->passage(1)];

        $this->generator->answer = GeneratedAnswer::unanswerable();
        $this->send('refund question one')->assertJson(['meta' => ['source' => 'unknown']]);

        $this->generator->answer = null;
        $this->send('refund question two')->assertJson(['meta' => ['source' => 'unknown']]);

        $this->generator->fail = true;
        $this->send('refund question three')->assertJson(['meta' => ['source' => 'unknown'], 'actions' => [['id' => 'escalate']]]);

        $this->assertCount(3, $this->generator->calls);
    }

    public function test_guests_get_no_document_answers_unless_enabled_and_then_public_documents_only(): void
    {
        $this->passages->results = [$this->passage(1)];
        $this->generator->answer = new GeneratedAnswer(true, 'Answer.', [1]);

        $this->send('refund question for guest', null)->assertJson(['meta' => ['source' => 'unknown']]);
        $this->assertSame([], $this->passages->searches);

        config(['smart-ai-assistant.documents.answers.guests' => true]);

        $this->send('refund question for guest', null)->assertJson(['meta' => ['source' => 'document']]);
        $this->assertSame(['public'], $this->passages->searches[0][1]);
    }

    public function test_canned_replies_and_knowledge_base_answers_come_first(): void
    {
        $this->passages->results = [$this->passage(1)];
        $this->generator->answer = new GeneratedAnswer(true, 'Answer.', [1]);

        $this->send('hello')->assertJson(['meta' => ['source' => 'greeting']]);

        $this->assertSame([], $this->passages->searches);
    }

    public function test_the_capability_is_off_by_default(): void
    {
        config(['smart-ai-assistant.capabilities.document_answers' => false]);
        $this->passages->results = [$this->passage(1)];

        $this->send('refund question')->assertJson(['meta' => ['source' => 'unknown']]);

        $this->assertSame([], $this->passages->searches);
    }

    public function test_daily_caps_stop_generator_calls(): void
    {
        $this->passages->results = [$this->passage(1)];
        $this->generator->answer = new GeneratedAnswer(true, 'Answer.', [1]);
        config(['smart-ai-assistant.documents.answers.daily_limit' => 10, 'smart-ai-assistant.documents.answers.per_user_daily_limit' => 2]);

        $this->send('refund question one')->assertJson(['meta' => ['source' => 'document']]);
        $this->send('refund question two')->assertJson(['meta' => ['source' => 'document']]);
        $this->send('refund question three')->assertJson(['meta' => ['source' => 'unknown']]);
        $this->send('refund question four', 43)->assertJson(['meta' => ['source' => 'document']]);

        config(['smart-ai-assistant.documents.answers.daily_limit' => 3]);
        $this->send('refund question five', 44)->assertJson(['meta' => ['source' => 'unknown']]);

        $this->assertCount(3, $this->generator->calls);
    }

    public function test_the_driver_config_picks_the_generator(): void
    {
        $this->app->forgetInstance(AnswerGenerator::class);
        $this->app->offsetUnset(AnswerGenerator::class);
        $this->app->register(\Subodh\SmartAiAssistant\SmartAiAssistantServiceProvider::class, true);

        foreach (['null' => NullAnswerGenerator::class, 'openai' => NullAnswerGenerator::class, 'anthropic' => AnthropicAnswerGenerator::class, FakeAnswerGenerator::class => FakeAnswerGenerator::class] as $driver => $class) {
            config(['smart-ai-assistant.ai.driver' => $driver]);
            $this->assertInstanceOf($class, app(AnswerGenerator::class), $driver);
        }
    }
}

class FakePassageSource implements PassageSource
{
    /** @var list<Passage> */
    public array $results = [];

    public array $searches = [];

    public function search(string $text, array $audiences, int $limit = 3): array
    {
        $this->searches[] = [$text, $audiences, $limit];

        return $this->results;
    }
}

class FakeAnswerGenerator implements \Subodh\SmartAiAssistant\Core\Contracts\AnswerGenerator
{
    public ?GeneratedAnswer $answer = null;

    public bool $fail = false;

    public array $calls = [];

    public function generate(string $question, array $passages, string $language): ?GeneratedAnswer
    {
        $this->calls[] = ['question' => $question, 'passages' => $passages, 'language' => $language];

        if ($this->fail) {
            throw new RuntimeException('LLM timed out');
        }

        return $this->answer;
    }
}
