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

    public function test_server_escalation_renders_no_user_data_inputs(): void
    {
        config(['smart-ai-assistant.features.server_escalation' => true]);
        Sentinel::actingAs((object) [
            'id'        => 7,
            'maddox_id' => 'MDX0007',
            'full_name' => 'Test Retailer',
            'phone_no'  => '9999999999',
        ]);

        $this->blade('<x-smart-assistant-widget />')
            ->assertDontSee('sa-user-maddox-id', false)
            ->assertDontSee('MDX0007', false)
            ->assertDontSee('9999999999', false);
    }

    public function test_widget_config_block_carries_endpoints_and_features(): void
    {
        config(['smart-ai-assistant.features.server_escalation' => true]);

        $html = (string) $this->blade('<x-smart-assistant-widget />');

        $this->assertMatchesRegularExpression('#<script type="application/json" id="sa-config">(.*?)</script>#s', $html);
        preg_match('#<script type="application/json" id="sa-config">(.*?)</script>#s', $html, $match);
        $this->assertSame([
            'endpoints' => ['help' => '/smart-assistant/help', 'escalate' => '/smart-assistant/escalate'],
            'features'  => ['server_escalation' => true],
        ], json_decode($match[1], true));
    }

    public function test_file_input_accepts_the_configured_attachment_types(): void
    {
        $this->blade('<x-smart-assistant-widget />')
            ->assertSee('accept=".jpg,.jpeg,.png,.pdf"', false);
    }

    public function test_html2canvas_is_bundled_and_loaded_before_the_widget_scripts(): void
    {
        $this->blade('<x-smart-assistant-widget />')
            ->assertDontSee('unpkg.com', false)
            ->assertSeeInOrder([
                'vendor/smart-ai-assistant/js/vendor/html2canvas.min.js',
                'vendor/smart-ai-assistant/js/ui-manager.js',
            ], false);
    }

    public function test_the_widget_loads_no_third_party_scripts(): void
    {
        $html = (string) $this->blade('<x-smart-assistant-widget />');

        preg_match_all('/<script[^>]+src="([^"]+)"/', $html, $matches);

        $this->assertNotEmpty($matches[1]);
        foreach ($matches[1] as $src) {
            $this->assertStringStartsWith(url('vendor/smart-ai-assistant/'), $src);
        }
    }

    public function test_bundled_html2canvas_file_ships_with_the_package_assets(): void
    {
        $file = __DIR__ . '/../../public/js/vendor/html2canvas.min.js';

        $this->assertFileExists($file);
        $this->assertStringContainsString('html2canvas 1.4.1', file_get_contents($file, false, null, 0, 200));
        $this->assertFileExists(__DIR__ . '/../../public/js/vendor/html2canvas.LICENSE.txt');
    }
}
