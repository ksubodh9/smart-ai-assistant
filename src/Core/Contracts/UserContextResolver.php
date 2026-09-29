<?php

namespace Subodh\SmartAiAssistant\Core\Contracts;

use Illuminate\Http\Request;
use Subodh\SmartAiAssistant\Core\Data\UserContext;

/**
 * The identity boundary: the host application decides who the user is.
 *
 * Implementations read the host's own authentication (a Laravel guard,
 * Sentinel, SSO, ...) and must never trust identity fields in the request body.
 * Return UserContext::guest() when nobody is logged in.
 */
interface UserContextResolver
{
    public function resolve(Request $request): UserContext;
}
