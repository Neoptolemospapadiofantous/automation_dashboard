# Ways to sign in and register

Three doors into the App, all landing on the same user + personal team
shape Fortify registration produces. Shipped 2026-09-13.

## Password (Fortify / Jetstream)

Unchanged: register with name, email, password; email verification; 2FA;
password reset. `App\Actions\Fortify\CreateNewUser` provisions the team.

## Continue with Google / Microsoft (`SocialAuthController`)

- Laravel Socialite; Microsoft via `socialiteproviders/microsoft`, registered
  on `SocialiteWasCalled` in `AppServiceProvider::boot()`.
- Config `services.google` / `services.microsoft` (`GOOGLE_CLIENT_ID`,
  `GOOGLE_CLIENT_SECRET`, `MICROSOFT_CLIENT_ID`, `MICROSOFT_CLIENT_SECRET`,
  `MICROSOFT_TENANT=common`). A provider without credentials is not offered:
  `App\Support\SignIn::configuredProviders()` drives the buttons (Inertia
  prop `socialProviders`) and its routes 404.
- Routes (guest): `GET /auth/{provider}` → redirect, `GET /auth/{provider}/callback`.
  Callback URLs to register in the consoles: `APP_URL/auth/google/callback`,
  `APP_URL/auth/microsoft/callback`.
- Resolution on callback: linked `social_accounts` row → user; else a user
  with the provider's (verified) email → link; else register (random
  password, `email_verified_at` set, personal team). A provider that returns
  no email is refused.
- 2FA is honoured: `SignIn::complete()` writes Fortify's `login.id` /
  `login.remember` session keys and redirects to the challenge instead of
  logging in.

## Sign in by email — one-time link (`MagicLinkController`)

- `POST /magic-link {email}` (throttle 5/min): for an existing user, a signed
  URL (15 min) carrying a cache-stored nonce is mailed
  (`MagicLinkNotification`, queued). The response is identical whether or
  not the address exists.
- `GET /magic-link/{user}?nonce=…` (`signed` middleware): the nonce is pulled
  from the cache, so the link works exactly once; then `SignIn::complete()`.
- Existing users only — registration collects a name through the form or a
  provider.

## Founder-side setup

1. Google Cloud → APIs & Services → OAuth consent screen (external) → create
   an OAuth client (web), add the callback URL, copy id + secret into the
   prod `.env`.
2. Azure → App registrations → new registration, supported account types
   "any org + personal" (matches `MICROSOFT_TENANT=common`), web redirect
   URI = the callback URL, a client secret → prod `.env`.
3. Both are `.env` only — no deploy needed beyond the one carrying the code.
