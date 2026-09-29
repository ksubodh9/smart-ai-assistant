<?php

namespace Subodh\SmartAiAssistant\Tests\Feature;

use Cartalyst\Sentinel\Laravel\Facades\Sentinel;
use Subodh\SmartAiAssistant\Tests\TestCase;

/**
 * Smoke tests for the <x-smart-assistant-widget /> Blade component.
 */
class WidgetComponentTest extends TestCase
{
    public function test_widget_renders_its_mount_points_and_scripts_in_order(): void
    {
        $view = $this->blade('<x-smart-assistant-widget />');

        foreach ([
            'id="smart-assistant-widget"',
            'id="smart-assistant-toggle"',
            'id="smart-assistant-panel"',
            'id="sa-chat-container"',
            'id="sa-error-tags"',
            'id="smart-assistant-chat-input"',
            'id="sa-send-btn"',
            'id="sa-file-upload"',
        ] as $fragment) {
            $view->assertSee($fragment, false);
        }

        $view->assertSeeInOrder([
            'vendor/smart-ai-assistant/js/ui-manager.js',
            'vendor/smart-ai-assistant/js/api-manager.js',
            'vendor/smart-ai-assistant/js/file-preview.js',
            'vendor/smart-ai-assistant/js/assistant.js',
        ], false);
    }

    public function test_guest_gets_no_user_data_inputs(): void
    {
        $this->blade('<x-smart-assistant-widget />')
            ->assertDontSee('sa-user-maddox-id', false);
    }

    public function test_known_gap_authenticated_user_pii_is_rendered_into_the_dom(): void
    {
        Sentinel::actingAs((object) [
            'id'        => 7,
            'maddox_id' => 'MDX0007',
            'full_name' => 'Test Retailer',
            'phone_no'  => '9999999999',
        ]);

        $this->blade('<x-smart-assistant-widget />')
            ->assertSee('id="sa-user-maddox-id" value="MDX0007"', false)
            ->assertSee('id="sa-user-name" value="Test Retailer"', false)
            ->assertSee('id="sa-user-phone" value="9999999999"', false);
    }

    public function test_known_gap_html2canvas_is_loaded_from_a_cdn_without_integrity(): void
    {
        $this->blade('<x-smart-assistant-widget />')
            ->assertSee('src="https://unpkg.com/html2canvas@1.4.1/dist/html2canvas.min.js"', false)
            ->assertDontSee('integrity=', false);
    }
}
