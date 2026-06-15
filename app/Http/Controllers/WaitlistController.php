<?php

namespace App\Http\Controllers;

use App\Models\WaitlistSignup;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Pre-launch waitlist capture for the coming-soon page.
 *
 * Renders the coming-soon view directly (success/error state) rather than
 * redirecting — the ComingSoon gate short-circuits before the session
 * middleware, so we don't rely on flashed session state here. CSRF-exempt
 * (see bootstrap/app.php) + throttled; a honeypot field filters bots.
 */
class WaitlistController extends Controller
{
    public function __invoke(Request $request): Response
    {
        // Honeypot: real users never fill this hidden field. Pretend success.
        if (filled($request->input('company'))) {
            return response()->view('coming-soon', ['submitted' => true]);
        }

        $email = Str::lower(trim((string) $request->input('email')));

        $validator = Validator::make(['email' => $email], [
            'email' => ['required', 'email:rfc', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->view('coming-soon', [
                'error' => 'Please enter a valid email address.',
                'email' => $request->input('email'),
            ], 422);
        }

        WaitlistSignup::firstOrCreate(
            ['email' => $email],
            ['source' => 'coming_soon', 'ip' => $request->ip()],
        );

        return response()->view('coming-soon', ['submitted' => true]);
    }
}
