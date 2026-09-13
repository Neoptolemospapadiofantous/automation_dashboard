<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\MagicLinkNotification;
use App\Support\SignIn;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Passwordless sign-in: type your email, get a link, click it, you are in.
 *
 * The link is a signed URL that expires in 15 minutes AND carries a nonce
 * stored in the cache, so it works exactly once — a forwarded or logged
 * link is dead after the first click. The request page always says the
 * same thing whether or not the address exists, so it cannot be used to
 * enumerate accounts. Existing users only: registration goes through the
 * form or a provider, where a name and terms are collected.
 */
class MagicLinkController extends Controller
{
    private const TTL_MINUTES = 15;

    public function send(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);
        $email = mb_strtolower(trim($data['email']));

        $user = User::query()->where('email', $email)->first();
        if ($user instanceof User) {
            $nonce = Str::random(32);
            Cache::put(self::cacheKey($user), $nonce, now()->addMinutes(self::TTL_MINUTES));

            $url = URL::temporarySignedRoute(
                'magic-link.login',
                now()->addMinutes(self::TTL_MINUTES),
                ['user' => $user->id, 'nonce' => $nonce],
            );

            rescue(fn () => $user->notify(new MagicLinkNotification($url, self::TTL_MINUTES)), report: true);
        }

        return back()->with('status', 'If that address has an account, a sign-in link is on its way. It works once and expires in 15 minutes.');
    }

    public function login(Request $request, User $user): RedirectResponse
    {
        $nonce = (string) $request->query('nonce', '');
        $expected = Cache::pull(self::cacheKey($user));

        if ($nonce === '' || ! is_string($expected) || ! hash_equals($expected, $nonce)) {
            return redirect()->route('login')->withErrors(['email' => 'That sign-in link has already been used or has expired. Request a new one.']);
        }

        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return SignIn::complete($request, $user);
    }

    private static function cacheKey(User $user): string
    {
        return 'magic-link:'.$user->id;
    }
}
