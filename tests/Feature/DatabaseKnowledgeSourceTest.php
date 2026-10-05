<?php

namespace Subodh\SmartAiAssistant\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Subodh\SmartAiAssistant\Core\Contracts\KnowledgeSource;
use Subodh\SmartAiAssistant\Core\Data\IncomingMessage;
use Subodh\SmartAiAssistant\Core\Data\StructuredProblem;
use Subodh\SmartAiAssistant\Knowledge\DatabaseKnowledgeSource;
use Subodh\SmartAiAssistant\Models\ErrorDefinition;
use Subodh\SmartAiAssistant\Tests\TestCase;

class DatabaseKnowledgeSourceTest extends TestCase
{
    use RefreshDatabase;

    private function find(string $text, string $service = 'AEPS'): array
    {
        return (new DatabaseKnowledgeSource($service))->find(
            new IncomingMessage($text),
            new StructuredProblem(StructuredProblem::INTENT_REPORT_ERROR)
        );
    }

    public function test_it_returns_a_knowledge_entry_for_a_substring_match(): void
    {
        $definition = ErrorDefinition::create([
            'service' => 'AEPS', 'key_text' => 'capture timeout',
            'answer_en' => 'Clean the scanner.', 'answer_hi' => 'स्कैनर साफ करें।',
        ]);

        $entries = $this->find('Biometric CAPTURE TIMEOUT occurred');

        $this->assertCount(1, $entries);
        $this->assertEquals($definition->id, $entries[0]->id);
        $this->assertSame('database', $entries[0]->sourceId);
        $this->assertSame('capture timeout', $entries[0]->key);
        $this->assertSame(['en' => 'Clean the scanner.', 'hi' => 'स्कैनर साफ करें।'], $entries[0]->content);
        $this->assertSame(['AEPS'], $entries[0]->domains);
    }

    public function test_it_returns_nothing_without_a_match_or_for_another_service(): void
    {
        ErrorDefinition::create(['service' => 'PAN', 'key_text' => 'capture timeout', 'answer_en' => 'x']);

        $this->assertSame([], $this->find('capture timeout'));
        $this->assertSame([], $this->find('something else', 'PAN'));
        $this->assertSame([], $this->find('   '));
        $this->assertCount(1, $this->find('capture timeout', 'PAN'));
    }

    public function test_the_container_builds_it_for_the_configured_service(): void
    {
        ErrorDefinition::create(['service' => 'PAN', 'key_text' => 'capture timeout', 'answer_en' => 'x']);
        config(['smart-ai-assistant.default_service' => 'PAN']);

        $entries = app(KnowledgeSource::class)->find(
            new IncomingMessage('capture timeout'),
            new StructuredProblem(StructuredProblem::INTENT_REPORT_ERROR)
        );

        $this->assertCount(1, $entries);
    }
}
