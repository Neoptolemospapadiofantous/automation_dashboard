<?php

use App\Billing\Exceptions\OutOfCredits;
use App\Billing\Exceptions\PlanLimitExceeded;
use App\Http\Middleware\HandleInertiaRequests;
use App\Lifecycle\InvalidTransition;
use App\Provisioning\PoolExhausted;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Inertia\Inertia;

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
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Stripe webhooks sign the body with a shared secret — no CSRF token
        // is sent. Verification happens inside StripeWebhookController via
        // Webhook::constructEvent(), which is the documented Stripe contract.
        $middleware->validateCsrfTokens(except: [
            'webhooks/stripe',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Out-of-credits is a billing/payment state, not an app error.
        // 402 Payment Required with a JSON payload the chat UI renders as
        // an upgrade prompt.
        $exceptions->render(function (OutOfCredits $e, $request) {
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

        // Plan limit (e.g. "5 agents max on Pro"). Previously handled
        // inline in AgentController::store only — any other caller (jobs,
        // API endpoints) would have leaked as 500. 403 Forbidden with
        // structured payload so the UI knows what limit was hit.
        $exceptions->render(function (PlanLimitExceeded $e, $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => $e->getMessage(),
                    'resource' => $e->resource,
                    'limit' => $e->limit,
                    'plan' => $e->plan->value,
                    'plan_label' => $e->plan->label(),
                ], 403);
            }

            return back()->with('flash.plan_limit', [
                'message' => $e->getMessage(),
                'plan' => $e->plan->value,
                'limit' => $e->limit,
                'resource' => $e->resource,
            ]);
        });

        // Voiceflow project pool empty — no available projects to allocate
        // for a managed-mode signup. 503 because it's a capacity issue
        // (operator needs to add more pool entries), not the user's fault.
        $exceptions->render(function (PoolExhausted $e, $request) {
            $payload = [
                'error' => 'We\'re at capacity right now. Please try again in a few minutes — we\'ve been notified.',
                'detail' => $e->getMessage(),
            ];

            if ($request->expectsJson()) {
                return response()->json($payload, 503);
            }

            return back()->withErrors(['provisioning' => $payload['error']]);
        });

        // State machine refusal (illegal transition / guard fail). Maps
        // to 422 Unprocessable so the kanban / status-change UIs render
        // it as a validation-style error instead of a 500.
        $exceptions->render(function (InvalidTransition $e, $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => $e->getMessage(),
                    'from' => $e->from instanceof BackedEnum ? $e->from->value : $e->from,
                    'to' => $e->to instanceof BackedEnum ? $e->to->value : $e->to,
                    'reason' => $e->reason,
                ], 422);
            }

            return back()->withErrors(['status' => $e->getMessage()]);
        });

        // Branded Inertia error pages instead of the stock Symfony
        // whoops / blank 500. Covers 404 / 403 / 419 / 429 / 503 / 500
        // with copy that matches the rest of the dashboard. JSON
        // requests still get a JSON error so APIs aren't affected.
        $exceptions->respond(function ($response, $exception, $request) {
            if ($request->expectsJson()) {
                return $response;
            }
            // Only intercept the "page" statuses; everything else falls
            // through to Laravel's default behavior (validation
            // redirects with errors, etc.).
            $intercepted = [403, 404, 419, 429, 500, 503];
            $status = (int) $response->getStatusCode();
            if (! in_array($status, $intercepted, true)) {
                return $response;
            }
            // Skip if we're already on the framework's debug screen
            // (APP_DEBUG=true). Operators need the raw trace there.
            if (config('app.debug') && $status >= 500) {
                return $response;
            }

            return Inertia::render('Errors/Error', ['status' => $status])
                ->toResponse($request)
                ->setStatusCode($status);
        });
    })->create();
