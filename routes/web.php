<?php

use Illuminate\Support\Facades\Route;
use Subodh\SmartAiAssistant\Http\Controllers\ErrorHelpController;

Route::group([
    'prefix' => 'smart-assistant',
    'middleware' => ['web'],
], function () {
    Route::post('/help', [ErrorHelpController::class, 'store'])->name('smart-assistant.help');
});
