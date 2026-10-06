<?php

namespace Subodh\SmartAiAssistant\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Subodh\SmartAiAssistant\Core\Contracts\DataTool;
use Subodh\SmartAiAssistant\Core\Data\ToolResult;
use Subodh\SmartAiAssistant\Core\Data\UserContext;
use Subodh\SmartAiAssistant\Models\Conversation;
use Subodh\SmartAiAssistant\Models\Message;
use Subodh\SmartAiAssistant\Tests\TestCase;

/**
 * DataToolStrategy: host data through a DataTool, only for the user it
 * belongs to, with the same reply for "not yours" and "does not exist".
 */
class DataToolTest extends TestCase
{
    use RefreshDatabase;

    private const NOT_FOUND = "I couldn't find that reference in your account. Please check the number, or raise a ticket.";

    protected function setUp(): void
    {
        parent::setUp();

        FakeTransactionTool::$executed = [];
        FakeTransactionTool::$fail = false;

        config([
            'smart-ai-assistant.capabilities.data_tools'   => true,
            'smart-ai-assistant.data_tools'                => [FakeTransactionTool::class],
            'smart-ai-assistant.understanding.entities'    => ['reference_id' => '/\b(TXN\d{6})\b/i'],
        ]);
    }

    private function send(string $text, ?int $userId = 42)
    {
        if ($userId !== null) {
            $this->actingAs(new GenericUser(['id' => $userId, 'name' => 'U']));
        }

        return $this->postJson('/smart-assistant/message', ['text' => $text]);
    }

    public function test_owner_gets_the_data_as_a_key_value_block(): void
    {
        $this->send('what is the status of TXN000001?')
            ->assertOk()
            ->assertJson([
                'blocks' => [
                    ['type' => 'key_value', 'title' => 'Transaction', 'items' => [
                        ['label' => 'Reference', 'value' => 'TXN000001'],
                        ['label' => 'Status', 'value' => 'Processing'],
                    ]],
                    ['type' => 'text', 'text' => 'Still processing.'],
                ],
                'actions' => [],
                'meta'    => ['source' => 'data_tool'],
                // Plain-text version for widgets that only read answer_en
                'answer_en' => "**Transaction**\nReference: TXN000001\nStatus: Processing\n\nStill processing.",
            ]);

        $this->assertSame([['42', 'TXN000001']], FakeTransactionTool::$executed);
        $this->assertSame('resolved', Conversation::sole()->status);
        $stored = Message::where('sender_type', 'ai')->sole();
        $this->assertSame('data_tool', $stored->data['source']);
        $this->assertSame('transaction_status', $stored->data['tool']);
        $this->assertStringContainsString('Status: Processing', $stored->message);
    }

    public function test_reference_is_matched_case_insensitively_by_the_host_pattern(): void
    {
        $this->send('txn000001 failed?')->assertJson(['meta' => ['source' => 'data_tool']]);

        $this->assertSame([['42', 'txn000001']], FakeTransactionTool::$executed);
    }

    public function test_someone_elses_reference_gets_the_same_reply_as_a_missing_one(): void
    {
        $other = $this->send('status TXN000002')->assertJson([
            'meta'      => ['source' => 'data_tool'],
            'answer_en' => self::NOT_FOUND,
            'actions'   => [['id' => 'escalate']],
        ]);
        $missing = $this->send('status TXN999999');

        $this->assertSame($other->json('answer_en'), $missing->json('answer_en'));
        $this->assertSame($other->json('blocks'), $missing->json('blocks'));
        $this->assertSame([], FakeTransactionTool::$executed, 'execute() never runs without authorization');
    }

    public function test_every_call_is_audited_without_the_arguments(): void
    {
        Log::spy();

        $this->send('status TXN000001');
        $this->send('status TXN000002');

        Log::shouldHaveReceived('info')->with('Smart assistant data tool', ['tool' => 'transaction_status', 'user_id' => '42', 'outcome' => 'found']);
        Log::shouldHaveReceived('info')->with('Smart assistant data tool', ['tool' => 'transaction_status', 'user_id' => '42', 'outcome' => 'denied']);
    }

    public function test_tool_failure_is_reported_without_leaking_details(): void
    {
        FakeTransactionTool::$fail = true;
        Log::spy();

        $this->send('status TXN000001')
            ->assertOk()
            ->assertJson([
                'answer_en' => "I couldn't check that right now. Please try again in a few minutes, or raise a ticket.",
                'actions'   => [['id' => 'escalate']],
            ])
            ->assertDontSee('database exploded');

        Log::shouldHaveReceived('info')->with('Smart assistant data tool', [
            'tool' => 'transaction_status', 'user_id' => '42', 'outcome' => 'failed', 'error' => RuntimeException::class,
        ]);
    }

    public function test_guests_never_reach_a_tool(): void
    {
        $this->send('status TXN000001', null)->assertJson(['meta' => ['source' => 'unknown']]);

        $this->assertSame([], FakeTransactionTool::$executed);
    }

    public function test_tools_do_not_run_while_the_capability_is_off(): void
    {
        config(['smart-ai-assistant.capabilities.data_tools' => false]);

        $this->send('status TXN000001')->assertJson(['meta' => ['source' => 'unknown']]);

        $this->assertSame([], FakeTransactionTool::$executed);
    }

    public function test_messages_without_the_entity_skip_the_tools(): void
    {
        $this->send('my transaction is stuck')->assertJson(['meta' => ['source' => 'unknown']]);

        $this->assertSame([], FakeTransactionTool::$executed);
    }

    public function test_asking_for_a_human_wins_over_a_data_lookup(): void
    {
        $this->send('TXN000001 is stuck, I want to talk to a human')->assertJson(['meta' => ['source' => 'escalation']]);

        $this->assertSame([], FakeTransactionTool::$executed);
    }

    public function test_a_configured_class_must_be_a_data_tool(): void
    {
        config(['smart-ai-assistant.data_tools' => [\stdClass::class]]);
        $this->withoutExceptionHandling();
        $this->expectException(\InvalidArgumentException::class);

        $this->send('status TXN000001');
    }
}

class FakeTransactionTool implements DataTool
{
    /** @var list<array{0: ?string, 1: string}> */
    public static array $executed = [];

    public static bool $fail = false;

    /** Reference => owner user id */
    private const OWNERS = ['TXN000001' => '42', 'TXN000002' => '7'];

    public function name(): string
    {
        return 'transaction_status';
    }

    public function description(): string
    {
        return 'Status of a transaction by its reference.';
    }

    public function argumentSchema(): array
    {
        return ['reference_id' => 'Transaction reference'];
    }

    public function authorize(UserContext $user, array $arguments): bool
    {
        if (self::$fail) {
            throw new RuntimeException('database exploded');
        }

        return (self::OWNERS[strtoupper($arguments['reference_id'])] ?? null) === $user->id;
    }

    public function execute(UserContext $user, array $arguments): ToolResult
    {
        self::$executed[] = [$user->id, $arguments['reference_id']];

        return ToolResult::found('Transaction', [
            'Reference' => strtoupper($arguments['reference_id']),
            'Status'    => 'Processing',
            'Remarks'   => null,
        ], 'Still processing.');
    }
}
