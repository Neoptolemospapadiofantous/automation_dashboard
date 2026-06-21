<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * Gate for the Hermes local operator pages (/hermes-metrics, /architecture).
 *
 * This is a developer/engineer tier that sits ABOVE the per-team Owner role: a
 * user qualifies only if their email is in the config('hermes.operators')
 * allowlist (env HERMES_OPERATOR_EMAILS), regardless of team role. A team Owner
 * who isn't on the list is denied — deliberately stricter than requireOwner().
 */
trait AuthorizesHermesOperator
{
    protected function requireHermesOperator(Request $request): void
    {
        $user = $request->user();
        $operators = config('hermes.operators', []);

        if ($user === null || ! in_array($user->email, $operators, true)) {
            abort(403, 'The Hermes operator pages are restricted to the platform engineer.');
        }
    }
}
