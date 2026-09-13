<?php

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use App\Models\Team;
use App\Models\User;
use App\Support\SignIn;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;

/**
 * "Continue with Google" / "Continue with Microsoft" — sign in AND sign up
 * through one door.
 *
 * Resolution order on the callback:
 *   1. a linked social account → that user;
 *   2. an existing user with the provider's email → link and sign in
 *      (both providers only return addresses they have verified);
 *   3. nobody → create the user + personal team exactly as Fortify
 *      registration does, email already verified, random password.
 *
 * A provider with no client id configured 404s, so the buttons and the
 * routes agree on what exists. Two-factor is honoured via SignIn::complete.
 */
class SocialAuthController extends Controller
{
    public function redirect(string $provider): SymfonyRedirect
    {
        abort_unless(SignIn::configured($provider), 404);

        return Socialite::driver($provider)->redirect();
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        abort_unless(SignIn::configured($provider), 404);

        try {
            $remote = Socialite::driver($provider)->user();
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('login')->withErrors(['email' => ucfirst($provider).' sign-in did not complete. Please try again.']);
        }

        $providerId = (string) $remote->getId();
        $email = mb_strtolower(trim((string) $remote->getEmail()));
        if ($providerId === '' || $email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return redirect()->route('login')->withErrors(['email' => ucfirst($provider).' did not share an email address for this account. Sign in with email instead.']);
        }

        $account = SocialAccount::query()->where('provider', $provider)->where('provider_id', $providerId)->first();
        $user = $account?->user;

        if (! $user instanceof User) {
            $user = User::query()->where('email', $email)->first();

            if (! $user instanceof User) {
                $user = $this->register($email, trim((string) $remote->getName()) ?: trim((string) $remote->getNickname()));
            }

            SocialAccount::create([
                'user_id' => $user->id,
                'provider' => $provider,
                'provider_id' => $providerId,
                'email' => $email,
                'name' => $remote->getName(),
                'avatar' => $remote->getAvatar(),
            ]);
        }

        // A provider-verified address verifies ours: no "check your inbox"
        // step for someone who just proved they own the mailbox.
        if ($user->email_verified_at === null && $user->email === $email) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return SignIn::complete($request, $user);
    }

    /**
     * Mirror of App\Actions\Fortify\CreateNewUser for a user who arrives
     * without a password: same team provisioning, so onboarding and
     * billing see exactly the shape a form registration produces.
     */
    private function register(string $email, string $name): User
    {
        $name = $name !== '' ? mb_substr($name, 0, 255) : Str::before($email, '@');

        return DB::transaction(function () use ($email, $name): User {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make(Str::random(48)),
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            $user->ownedTeams()->save(Team::forceCreate([
                'user_id' => $user->id,
                'name' => explode(' ', $name, 2)[0]."'s Team",
                'personal_team' => true,
            ]));

            return $user;
        });
    }
}
