<?php

namespace Subodh\SmartAiAssistant\Tests\Feature;

use Subodh\SmartAiAssistant\Tests\TestCase;

/**
 * A host app that does not use Sentinel (no global "Sentinel" alias).
 */
class WidgetWithoutSentinelTest extends TestCase
{
    protected function getPackageAliases($app)
    {
        return [];
    }

    public function test_widget_renders_without_sentinel_and_without_user_data(): void
    {
        $this->blade('<x-smart-assistant-widget />')
            ->assertSee('id="smart-assistant-widget"', false)
            ->assertDontSee('sa-user-maddox-id', false);
    }
}
