<?php

namespace Subodh\SmartAiAssistant\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Subodh\SmartAiAssistant\Models\ErrorDefinition;
use Subodh\SmartAiAssistant\Tests\TestCase;

class SeedKbCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = tempnam(sys_get_temp_dir(), 'kb') . '.csv';
        file_put_contents($this->file, implode("\n", [
            'Question,Answer EN,Answer HI',
            'capture timeout,Clean the scanner.,स्कैनर साफ करें।',
            ',,',
            ',orphan answer,',
            'invalid otp,Request a new OTP.,',
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->file);

        parent::tearDown();
    }

    public function test_it_seeds_rows_for_the_default_service_and_skips_header_and_blank_keys(): void
    {
        $this->artisan('smart-ai:seed-kb', ['file' => $this->file])
            ->expectsOutputToContain('Successfully seeded 2 entries into the Knowledge Base (AEPS).')
            ->assertExitCode(0);

        $this->assertSame(['capture timeout', 'invalid otp'], ErrorDefinition::orderBy('id')->pluck('key_text')->all());
        $this->assertSame(['AEPS'], ErrorDefinition::distinct()->pluck('service')->all());
        $this->assertSame('स्कैनर साफ करें।', ErrorDefinition::where('key_text', 'capture timeout')->value('answer_hi'));
    }

    public function test_domain_option_sets_the_service(): void
    {
        $this->artisan('smart-ai:seed-kb', ['file' => $this->file, '--domain' => 'PAN'])->assertExitCode(0);

        $this->assertSame(['PAN'], ErrorDefinition::distinct()->pluck('service')->all());
    }

    public function test_reseeding_updates_existing_entries_instead_of_duplicating(): void
    {
        $this->artisan('smart-ai:seed-kb', ['file' => $this->file])->assertExitCode(0);
        file_put_contents($this->file, "Q,EN,HI\ncapture timeout,Restart the RD service.,\n");

        $this->artisan('smart-ai:seed-kb', ['file' => $this->file])->assertExitCode(0);

        $this->assertSame(2, ErrorDefinition::count());
        $this->assertSame('Restart the RD service.', ErrorDefinition::where('key_text', 'capture timeout')->value('answer_en'));
    }

    public function test_missing_file_fails(): void
    {
        $this->artisan('smart-ai:seed-kb', ['file' => $this->file . '.missing'])->assertExitCode(1);
    }
}
