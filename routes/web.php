<?php

use Illuminate\Support\Facades\Route;
use OcGlobalTech\CashierFiuu\Http\Controllers\WebhookController;

Route::group(['prefix' => config('cashier.path'), 'as' => 'cashier.'], function () {
    // Fiuu posts the browser back here; it proves nothing and only redirects.
    Route::match(['get', 'post'], 'return', [WebhookController::class, 'return'])->name('return');

    // The server to server webhooks that actually settle a payment.
    Route::post('notify', [WebhookController::class, 'notify'])->name('notify');
    Route::post('callback', [WebhookController::class, 'callback'])->name('callback');

    // The merchant portal's "Check" button probes these URLs with a bare GET
    // and reports anything but a 2xx as broken. A GET settles nothing.
    Route::get('{endpoint}', fn () => 'OK')->whereIn('endpoint', ['notify', 'callback']);
});
