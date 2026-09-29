<?php

namespace Subodh\SmartAiAssistant\Support;

use Illuminate\Http\Request;
use Subodh\SmartAiAssistant\Core\Contracts\UserContextResolver;
use Subodh\SmartAiAssistant\Core\Data\UserContext;

/**
 * Default resolver: the user of the default Laravel auth guard.
 *
 * Works with anything built on Laravel guards (session auth, Breeze,
 * Jetstream, Fortify, Sanctum, Passport). Hosts with other authentication
 * set 'user_resolver' in config/smart-ai-assistant.php to their own class.
 */
class LaravelAuthUserContextResolver implements UserContextResolver
{
    public function resolve(Request $request): UserContext
    {
        $locale = app()->getLocale();
        $user = $request->user();

        if (! $user) {
            return UserContext::guest($locale);
        }

        return new UserContext(
            id: (string) $user->getAuthIdentifier(),
            displayName: $user->name ?? null,
            locale: $locale,
        );
    }
}
