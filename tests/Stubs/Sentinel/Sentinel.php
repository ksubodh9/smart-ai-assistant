<?php

namespace Cartalyst\Sentinel\Laravel\Facades;

/**
 * Test-only stand-in for the host app's Cartalyst Sentinel facade.
 *
 * The package still calls Sentinel directly (controller and widget view).
 * This stub is autoloaded only through autoload-dev and is removed once
 * identity moves behind a UserContextResolver (plan step 2).
 */
class Sentinel
{
    protected static ?object $user = null;

    public static function actingAs(?object $user): void
    {
        static::$user = $user;
    }

    public static function reset(): void
    {
        static::$user = null;
    }

    public static function check()
    {
        return static::$user ?? false;
    }

    public static function getUser()
    {
        return static::$user;
    }
}
