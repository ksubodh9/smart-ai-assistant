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
}
