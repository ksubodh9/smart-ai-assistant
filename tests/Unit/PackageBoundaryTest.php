<?php

namespace Subodh\SmartAiAssistant\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Guards the package/host boundary: package PHP code must not depend on a
 * specific host's authentication library. Identity goes through
 * UserContextResolver (see PLATFORM_PLAN.md, section 4).
 */
class PackageBoundaryTest extends TestCase
{
    public function test_package_source_does_not_reference_sentinel(): void
    {
        $offenders = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../src'));

        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php'
                // Code references only (namespace, facade calls, imports); comments may mention hosts
                && preg_match('/Cartalyst\\\\|Sentinel::|use\s+Sentinel\b/', file_get_contents($file->getPathname()))) {
                $offenders[] = $file->getFilename();
            }
        }

        $this->assertSame([], $offenders, 'Package source must not reference Sentinel');
    }

    public function test_package_code_and_config_carry_no_maddoxpay_vocabulary(): void
    {
        // Services, categories and Hinglish patterns belong in the host's config
        $vocabulary = '/\b(aeps|irctc|recharge|payout|maddox\w*|namaste|gadbad|bakwas|chutiya)\b/i';
        $offenders = [];

        foreach (['src', 'config'] as $dir) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../' . $dir));

            foreach ($files as $file) {
                if ($file->isFile() && $file->getExtension() === 'php'
                    && preg_match($vocabulary, file_get_contents($file->getPathname()), $match)) {
                    $offenders[] = "{$dir}/{$file->getFilename()}: {$match[0]}";
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_widget_view_and_scripts_carry_no_host_branding_or_vocabulary(): void
    {
        // Branding and scan rules come from config('smart-ai-assistant.widget').
        // Blocks between legacy-host-start and legacy-host-end are the old
        // MaddoxPay ticket path and scan rules, kept until the flags are default-on.
        $vocabulary = '/\b(aeps|irctc|recharge|payout|maddox\w*|soniya|namaste|withdrawal|fingerprint)\b/i';
        $files = array_merge(
            glob(__DIR__ . '/../../resources/views/components/*.blade.php'),
            glob(__DIR__ . '/../../public/js/*.js'),   // not js/vendor
            [__DIR__ . '/../../public/css/assistant.css'],
        );
        $offenders = [];

        foreach ($files as $file) {
            $code = preg_replace('/legacy-host-start.*?legacy-host-end/s', '', file_get_contents($file));

            if (preg_match($vocabulary, $code, $match)) {
                $offenders[] = basename($file) . ": {$match[0]}";
            }
        }

        $this->assertNotEmpty($files);
        $this->assertSame([], $offenders);
    }
}
