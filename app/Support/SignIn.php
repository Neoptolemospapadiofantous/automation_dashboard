<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The bits shared by every non-password way in (Google, Microsoft, the
 * emailed magic link): which providers are switched on, and how to log a
 * user in WITHOUT skipping two-factor. Fortify's password login runs the
 * 2FA challenge in its pipeline; a social or link login must honour the
 * same challenge, or enabling 2FA would protect only one door.
 */
final class SignIn
{
    /** Providers this installation can offer, in button order. */
    public const PROVIDERS = ['google', 'microsoft'];

    /**
     * @return list<string>
     */
    public static function configuredProviders(): array
    {
        return array_values(array_filter(self::PROVIDERS, fn (string $p): bool => self::configured($p)));
    }

    public static function configured(string $provider): bool
    {
        return in_array($provider, self::PROVIDERS, true)
            && (string) config("services.{$provider}.client_id") !== ''
            && (string) config("services.{$provider}.client_secret") !== '';
    }

    /**
     * Log the user in, or hand them to the two-factor challenge exactly as
     * Fortify does (the same session keys its challenge controller reads).
     */
    public static function complete(Request $request, User $user, bool $remember = true): RedirectResponse
    {
        if ($user->two_factor_secret !== null && $user->two_factor_confirmed_at !== null) {
            $request->session()->put([
                'login.id' => $user->getKey(),
                'login.remember' => $remember,
            ]);

            return redirect()->route('two-factor.login');
        }

        Auth::login($user, $remember);
        $request->session()->regenerate();

        return redirect()->intended(config('fortify.home', '/dashboard'));
    }
}
