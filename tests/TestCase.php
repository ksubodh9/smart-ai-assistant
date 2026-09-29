<?php

namespace Subodh\SmartAiAssistant\Tests;

use Cartalyst\Sentinel\Laravel\Facades\Sentinel;
use Orchestra\Testbench\TestCase as Orchestra;
use Subodh\SmartAiAssistant\SmartAiAssistantServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Sentinel::reset();
    }

    protected function getPackageProviders($app)
    {
        return [SmartAiAssistantServiceProvider::class];
    }

    protected function getPackageAliases($app)
    {
        // The widget view calls Sentinel through the host app's global alias.
        return ['Sentinel' => Sentinel::class];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('database.default', 'smart_ai_test');
        $app['config']->set('database.connections.smart_ai_test', [
            'driver'    => 'mysql',
            'host'      => env('SMART_AI_TEST_DB_HOST', '127.0.0.1'),
            'port'      => env('SMART_AI_TEST_DB_PORT', '3306'),
            'database'  => env('SMART_AI_TEST_DB_DATABASE', 'smart_ai_assistant_test'),
            'username'  => env('SMART_AI_TEST_DB_USERNAME', 'root'),
            'password'  => env('SMART_AI_TEST_DB_PASSWORD', ''),
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => '',
            'strict'    => true,
        ]);
    }
}
