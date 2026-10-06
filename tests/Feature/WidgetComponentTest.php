<?php

namespace Subodh\SmartAiAssistant\Tests\Feature;

use Cartalyst\Sentinel\Laravel\Facades\Sentinel;
use Subodh\SmartAiAssistant\Support\WidgetConfig;
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

    /**
     * The JSON the widget scripts read from the page.
     */
    private function scriptConfig(): array
    {
        $html = (string) $this->blade('<x-smart-assistant-widget />');

        $this->assertMatchesRegularExpression('#<script type="application/json" id="sa-config">(.*?)</script>#s', $html);
        preg_match('#<script type="application/json" id="sa-config">(.*?)</script>#s', $html, $match);

        return json_decode($match[1], true);
    }

    public function test_widget_config_block_carries_endpoints_and_features(): void
    {
        config(['smart-ai-assistant.features.server_escalation' => true]);

        $config = $this->scriptConfig();

        $this->assertSame([
            'help'     => '/smart-assistant/help',
            'message'  => '/smart-assistant/message',
            'escalate' => '/smart-assistant/escalate',
        ], $config['endpoints']);
        $this->assertSame(['server_escalation' => true, 'resolve_typed_messages' => false], $config['features']);
        $this->assertSame(WidgetConfig::DEFAULTS['features'], $config['widget']['features']);
        $this->assertSame(WidgetConfig::DEFAULTS['page_scan'], $config['widget']['page_scan']);
    }

    public function test_default_branding_is_generic(): void
    {
        $this->blade('<x-smart-assistant-widget />')
            ->assertSee('Support assistant')
            ->assertSee('Hello!')
            ->assertSee('How may I assist you today?')
            ->assertDontSee('class="sa-footer"', false)
            ->assertSee('--sa-primary: #667eea;', false)
            ->assertSee('--sa-primary-rgb: 102, 126, 234;', false);
    }

    public function test_branding_comes_from_config_and_is_escaped(): void
    {
        config(['smart-ai-assistant.widget' => ['branding' => [
            'title'           => 'Help <b>desk</b>',
            'welcome_title'   => "Hello! I'm Asha",
            'footer'          => 'Powered by Example',
            'primary_color'   => '#0a0',
            'secondary_color' => 'red; } body { display:none',
        ]]]);

        $this->blade('<x-smart-assistant-widget />')
            ->assertSee('Help &lt;b&gt;desk&lt;/b&gt;', false)
            ->assertSee("Hello! I'm Asha")
            ->assertSee('Powered by Example')
            ->assertSee('--sa-primary: #0a0;', false)
            ->assertSee('--sa-primary-rgb: 0, 170, 0;', false)
            // Not a hex colour: the default is used instead
            ->assertSee('--sa-secondary: #764ba2;', false)
            ->assertDontSee('display:none', false)
            // Keys not set keep their defaults
            ->assertSee('How may I assist you today?');
    }

    public function test_host_page_scan_rules_replace_only_the_keys_given(): void
    {
        config(['smart-ai-assistant.widget' => ['page_scan' => ['ids' => ['modal_error']]]]);

        $scan = $this->scriptConfig()['widget']['page_scan'];

        $this->assertSame(['modal_error'], $scan['ids']);
        $this->assertSame(['.alert-danger', '.smart-error'], $scan['selectors']);
    }

    public function test_features_hide_parts_of_the_widget(): void
    {
        config(['smart-ai-assistant.widget' => ['features' => ['attachments' => false, 'screenshot' => false]]]);

        $this->blade('<x-smart-assistant-widget />')
            ->assertDontSee('id="sa-attach-btn"', false)
            ->assertDontSee('id="sa-file-upload"', false)
            ->assertDontSee('id="sa-screenshot-btn"', false)
            ->assertDontSee('html2canvas.min.js', false);
    }

    public function test_bootstrap_compat_script_is_only_loaded_when_enabled(): void
    {
        $this->blade('<x-smart-assistant-widget />')->assertDontSee('host-compat.js', false);

        config(['smart-ai-assistant.widget' => ['features' => ['bootstrap_modal_compat' => true]]]);

        $this->blade('<x-smart-assistant-widget />')->assertSeeInOrder([
            'js/ui-manager.js',
            'js/host-compat.js',
            'js/assistant.js',
        ], false);
    }

    public function test_suggestions_need_typed_message_resolution(): void
    {
        config(['smart-ai-assistant.widget' => ['suggestions' => ['Money deducted', '<script>x</script>']]]);

        $this->blade('<x-smart-assistant-widget />')->assertDontSee('sa-suggestion', false);

        config(['smart-ai-assistant.features.resolve_typed_messages' => true]);

        $this->blade('<x-smart-assistant-widget />')
            ->assertSee('data-send="Money deducted"', false)
            ->assertSee('&lt;script&gt;x&lt;/script&gt;', false)
            ->assertDontSee('<script>x</script>', false);
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
