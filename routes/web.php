<?php

use Illuminate\Support\Facades\Route;
use Subodh\SmartAiAssistant\Http\Controllers\ErrorHelpController;
use Subodh\SmartAiAssistant\Http\Controllers\EscalationController;

Route::group([
    'prefix' => 'smart-assistant',
    // Host middleware (session, auth) runs first, then the package rate limit
    'middleware' => array_merge(
        (array) config('smart-ai-assistant.middleware', ['web']),
        ['throttle:smart-assistant']
    ),
], function () {
    Route::post('/help', [ErrorHelpController::class, 'store'])->name('smart-assistant.help');
    Route::post('/escalate', [EscalationController::class, 'store'])->name('smart-assistant.escalate');
});
