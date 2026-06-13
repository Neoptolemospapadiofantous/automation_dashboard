# Security — engineering posture

> The code is the source of truth. This doc explains the engineering
> security posture — the boundaries, the headers, the isolation guarantees
> — and is grounded in `tests/Security/`, which *pins* each guarantee so it
> regresses loudly. It is distinct from the legal trust page
> (`docs/legal/trust-page.md`) and from [[authorization]], which covers the
> role model only. See also [[public-surface]] and [[project-overview]].

Authentication is stock Jetstream + Fortify + Sanctum (session guard `web`).
For *who can do what once logged in*, read [[authorization]] — this doc does
not duplicate the role matrix.

## 1. Boundaries

Three surfaces, three threat models. Each gets a different protection set:

| Surface | Auth | Runs where | Protections |
|---|---|---|---|
| **Dashboard** | session (`auth:sanctum` + `verified` + `RequireAgent`) | our origin, in the user's browser | CSRF, `SAMEORIGIN`, team-scoped queries + policies, per-route throttles, mass-assignment guards |
| **Public surface** | anonymous | our origin | per-IP throttle, server-side response cache, no tenant data (`/api/public/stats`, `/api/health`, `/`) |
| **Embed widget** | per-agent-slug (agent must be `active`); no user account | the **customer's** site, inside an iframe | deliberately frameable (`frame-ancestors *`), cookie-scoped visitor id, per-IP throttle, free-greeting abuse cap, Art. 50 disclosure |

The dashboard group lives behind `auth:sanctum` + `verified` +
`RequireAgent` (`routes/web.php`). The embed and the Stripe webhook live
*outside* that group on purpose — they receive requests that carry no
session cookie or CSRF token (`app/Http/Controllers/EmbedController.php`,
`app/Http/Controllers/StripeWebhookController.php`).

## 2. Headers & framing

`app/Http/Middleware/SecurityHeaders.php` sets a baseline on **every** web
response:

| Header | Value | Why |
|---|---|---|
| `X-Content-Type-Options` | `nosniff` | stop MIME sniffing of responses |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | don't leak full dashboard URLs to third parties |
| `X-Frame-Options` | `SAMEORIGIN` | clickjacking guard — **only set if absent** |

The "only if absent" is load-bearing. The embed chat page
(`EmbedController::chat`) is an iframe *product*: it deliberately emits
`Content-Security-Policy: frame-ancestors *` and `X-Frame-Options: ALLOWALL`
so customers can frame it from any domain. If `SecurityHeaders` overwrote
that with `SAMEORIGIN`, every installed widget would silently break. The
baseline `nosniff` + `Referrer-Policy` still apply to the embed — only the
*framing* headers are surrendered.

Pinned by `tests/Security/HeadersTest.php`:
- dashboard + guest pages carry all three baseline headers (`SAMEORIGIN`);
- the embed chat page keeps `frame-ancestors *` + `ALLOWALL` and the
  middleware does **not** override it, while still carrying `nosniff` +
  `Referrer-Policy`.

## 3. Tenant isolation

Every tenant-owned row carries a `team_id` (and usually `agent_id`).
Controllers scope reads to `currentTeam` / `currentAgent` and policies
deny cross-team access. Two layers of defence are tested:

**Cross-tenant reads** (`tests/Security/CrossTenantTest.php`) — User A on
team A cannot reach team B's resources by URL-guessing:
- `agents.show` for another team's agent → **403**;
- another team's lead → **403**;
- another team's conversation → **403 or 404** (existence may be hidden);
- another agent's KB document → **404** (the lookup is agent-scoped, so it
  reads as nonexistent);
- an inactive agent slug on `embed.launch` → **404**.

**Mass assignment** (`tests/Security/MassAssignmentTest.php`):
- no Eloquent model ships fully unguarded (`$guarded === []` is banned for
  every non-pivot model — that would turn every write into an over-posting
  hole). Pivot models (Jetstream's `Membership`) are exempt because they're
  only written by code-driven `attach()`/`sync()`, never from request input.
- privileged fields can't be smuggled in: `agents.update` ignores an
  over-posted `status` and `team_id`; `leads.store` stamps `team_id` from
  `currentTeam`, never from the request body.

## 4. Auth & rate limiting

**Fortify throttles** (`config/fortify.php`): the public auth endpoints
(register, forgot/reset-password) carry `throttle:30,1` in the global
middleware stack — an anti-spam / mail-bombing fix (audit finding
2026-06-12). Login additionally has Fortify's own `LoginRateLimiter` on top
(per email+IP). Passwords use `Password::default()`
(`app/Actions/Fortify/PasswordValidationRules.php`); 2FA (TOTP, confirmed)
is enabled. Passkeys are deliberately **disabled** (the contract was never
implemented on `User`).

**Sessions** (`config/session.php`): database driver; `http_only` cookies;
`SameSite=lax`; `secure` driven by env (set in production). Lifetime 120 min.

**Route throttles** — abuse-sensitive routes each carry a `throttle:*`
middleware (`routes/web.php`, `routes/api.php`): public stats + health
`60,1`; embed `launch` `60,1` / `interact` `120,1`; chat `interact` `60,1`;
KB `query` `60,1`; onboarding `start` `5,1`; the various write endpoints
`10–120/min`. `tests/Security/ThrottleTest.php` asserts a `throttle:*`
middleware is *registered* on every abuse-sensitive route — the invariant
that regresses when someone re-declares a route and forgets the limiter.

## 5. Webhooks & secrets

**Stripe webhook** (`app/Http/Controllers/StripeWebhookController.php`) is
the only inbound webhook. It runs outside the auth group with no CSRF; the
guard is the signature: `StripeClient::verifyWebhook($payload, $sigHeader)`
verifies the `Stripe-Signature` against the webhook secret (`whsec_`,
constant-time) before any handler runs. A bad signature → logged warning +
`400`, no side effects. Handlers are idempotent (Stripe retries), so a
replay can't double-grant credits. It is deliberately **not** IP-throttled —
the signature is the guard, and throttling would risk dropping renewal
bursts.

**Secrets** live in `.env` (never committed) and are read via
`config('services.*')` / `config('runtime.*')` — never hardcoded.
`tests/Security/LogRedactionTest::test_gitignore_keeps_env_secrets_out_of_git`
pins that `.gitignore` covers `.env` and its variants while keeping
`.env.example` committable.

**Log redaction** (`tests/Security/LogRedactionTest.php`): the runtime's
ops logging must never include raw visitor message content. The
`ToolRegistry` failure path logs `Runtime tool failed` with exactly
`{tool, agent_id, error}` — the test dispatches an exploding tool with a
sentinel visitor message and asserts the sentinel reaches no log call.

**Exception hygiene** (`tests/Security/ExceptionLeakTest.php`): with
`APP_DEBUG=false` (production posture), error bodies must not leak
internals. A JSON 404 (model-binding miss) and a 422 (validation) are
asserted to carry no `file`, `trace`, `exception`, or `line` keys.

## 6. Embed & visitor sessions

The embed serves a plain Blade chat page (no Inertia/SPA bundle) into the
customer's iframe. Visitor identity is **a cookie, not a signed token** —
this is intentional and is mirrored honestly below in §7:

- `fs_embed_{slug}`: a 30-day cookie holding a random `embed-…` visitor id
  for conversation continuity (`EmbedController::launch`). Set with
  `SameSite=none` + `secure` + `httpOnly` so it survives the cross-site
  iframe context. The visitor has no Flowstack account.
- **Art. 50 disclosure** — the EU AI Act transparency line is rendered by
  the *platform* at the top of every embed chat, independent of agent
  scripting: `resources/views/embed/chat.blade.php` →
  *"AI assistant — not a person. You can ask for a human at any time."*
  Pinned by `HeadersTest::test_embed_chat_carries_the_ai_disclosure` (and a
  companion test asserting the engine system prompt forbids claiming to be
  human).
- **Free-greeting abuse cap** — `launch()` greetings are free up to
  `runtime.safety.free_greetings_per_day` per team, then debit the tier
  multiplier. Greetings are otherwise a token-burn vector for bots spread
  across IPs that the per-IP throttle alone can't see (the throttle counts
  requests per IP, not aggregate launches per team).

## 7. Known gaps / not-yet (honest)

Mirrors the ❌ / ⚠️ rows of `docs/legal/claims-vs-reality.md`. Do not claim
these as "in place":

| Gap | Reality |
|---|---|
| Key rotation | Env keys; **no** rotation schedule. |
| Encryption at rest | Whatever the host DB provides — **not** app-layer. No `encrypted` casts / `$hidden` secret columns exist in `app/Models`. Don't claim until a deploy target is chosen. |
| General audit log | Only the **credit ledger** is append-only (`CreditTransaction`). There is no general action/access audit log. |
| Signed session tokens for chat | Embed identity is a plain cookie (§6), not a signed token. |
| PII redaction at the engine layer | None. Transcripts store full message content; only *ops logs* are redacted. |
| Data-subject rights / export | Manual deletion exists; agent config exports exist; no formal DSR intake or full customer-data export. |
| Breach runbook | Drafted (`docs/legal/breach-runbook.md`) but **not** adopted/rehearsed — roles unfilled, no tabletop. |
| Terms/Privacy acceptance at registration | Jetstream feature ready but **off** in `config/jetstream.php`; enable only when counsel approves the policy text. |
| WCAG 2.1 AA | Never assessed. |

## Cross-references

- [[authorization]] — the role model and the capability matrix (who can do
  what once authenticated).
- [[public-surface]] — the anonymous/embed route table in full.
- [[project-overview]] — system context.
- `docs/legal/claims-vs-reality.md` — what may/may not be claimed publicly.
