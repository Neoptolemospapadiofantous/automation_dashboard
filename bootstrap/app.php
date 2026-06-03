<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Out-of-credits is a billing/payment state, not an app error.
        // Map to HTTP 402 with a JSON payload the chat UI knows how to
        // render as an upgrade prompt.
        $exceptions->render(function (\App\Billing\Exceptions\OutOfCredits $e, $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => $e->getMessage(),
                    'plan' => $e->plan->value,
                    'plan_label' => $e->plan->label(),
                    'allows_topups' => $e->plan->allowsTopUps(),
                ], 402);
            }

            return back()->withErrors(['credits' => $e->getMessage()]);
        });
    })->create();
