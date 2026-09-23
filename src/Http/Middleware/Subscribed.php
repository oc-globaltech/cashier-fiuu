<?php

namespace OcGlobalTech\CashierFiuu\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps unsubscribed customers out of a route.
 *
 * Route::get('/dashboard', ...)->middleware('subscribed');
 * Route::get('/reports', ...)->middleware('subscribed:default,pro');
 */
class Subscribed
{
    public function handle(Request $request, Closure $next, string $type = 'default', ?string $plan = null): Response
    {
        $user = $request->user();

        if (! $user || ! $user->subscribed($type, $plan)) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Subscription required.'], Response::HTTP_PAYMENT_REQUIRED)
                : redirect(config('cashier.subscribe_redirect', '/billing'));
        }

        return $next($request);
    }
}
