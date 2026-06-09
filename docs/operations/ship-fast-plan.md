---
type: runbook
status: active
tags: [operations, deployment, launch]
date: 2026-06-09
---

# Ship-Fast Plan — Flowstack to first paying customer

7-day path from "branch with working code" to "real customer paying real money."

Assumes:
- You've finished local manual testing (you have)
- Voiceflow account ready (yours: `neoptolemos.papadiofantous@gmail.com`)
- Dashboard branch `voiceflow-wrapper-and-hermes-system` ready to deploy

## Day 0 — Upgrade Voiceflow to Pro (15 min, $60/mo)

URL: https://creator.voiceflow.com/profile/billing → upgrade to **Pro monthly** ($60/mo, not annual — keep optionality).

Unlocks:
- 20 project quota (= up to 20 customers in managed mode)
- **Workspace API key** (enables Evaluations / KB CRUD / transcripts)
- Higher LLM credit allowance

Get the workspace API key:
1. After upgrade, refresh https://creator.voiceflow.com/workspace-settings/api
2. Generate a new key labeled "automation-dashboard-prod"
3. Copy it once shown — Voiceflow only displays it once

This goes into Railway env vars on Day 1.

## Day 1 — Production deploy on Railway (4-6 hours)

### Step 1.1 — Create Railway project

```
1. Sign up at https://railway.app  (GitHub login)
2. New Project → Deploy from GitHub repo
3. Pick this repo (automation_dashboard)
4. Branch: voiceflow-wrapper-and-hermes-system
   (later: change to main once you merge)
5. Railway detects Laravel via composer.json
```

### Step 1.2 — Add a PostgreSQL plugin

Railway dashboard → New → Database → PostgreSQL. Railway injects `DATABASE_URL` automatically.

In `config/database.php`, `pgsql` connection is already supported by default Laravel. Update `.env` for prod to use it:

```env
DB_CONNECTION=pgsql
```

(Railway sets DATABASE_URL; Laravel reads it via `connection` parser.)

### Step 1.3 — Set environment variables in Railway

Railway dashboard → Variables. Paste:

```env
APP_NAME=Flowstack
APP_ENV=production
APP_KEY=base64:...         # generate with `php artisan key:generate --show`
APP_DEBUG=false
APP_URL=https://app.flowstack.com    # set after Day 2 domain
LOG_LEVEL=warning

DB_CONNECTION=pgsql
# DATABASE_URL set by Railway PostgreSQL plugin

# Voiceflow — your account + the workspace key from Day 0
# NEVER commit real keys to the repo. Paste your real values directly
# into Railway's Variables panel; this runbook keeps placeholders only.
VOICEFLOW_API_KEY=VF.DM.<your-dm-key>
VOICEFLOW_PROJECT_ID=<your-24-hex-project-id>
VOICEFLOW_WORKSPACE_API_KEY=<paste-the-new-workspace-key>
VOICEFLOW_ENVIRONMENT=main

# Mail (filled in Day 2)
MAIL_MAILER=resend
RESEND_API_KEY=                 # Day 2  (note: RESEND_API_KEY, not RESEND_KEY)
MAIL_FROM_ADDRESS=hello@flowstack.com
MAIL_FROM_NAME=Flowstack

# Cache + session — Railway has Redis plugin if you want it, else file is fine
CACHE_STORE=file
SESSION_DRIVER=file
QUEUE_CONNECTION=database

# Voiceflow webhook secrets (optional — falls back to per-agent secrets)
VOICEFLOW_ORG_WEBHOOK_SECRET=<generate-random-40-char-string>
VOICEFLOW_SVIX_SECRET=                    # leave empty until Voiceflow configures Svix
```

### Step 1.4 — Add a deploy hook for migrations + cache

Railway dashboard → Settings → Deploy → Build & Start command. Replace with:

```
composer install --no-dev --optimize-autoloader
&& php artisan key:generate --force --no-interaction
&& php artisan config:cache
&& php artisan route:cache
&& php artisan migrate --force
&& php artisan storage:link
```

Then start command:
```
php artisan octane:start --host=0.0.0.0 --port=$PORT
```

(Or `php -S 0.0.0.0:$PORT -t public` for cheaper FrankenPHP-less startup.)

### Step 1.5 — Add a vite build step

Railway needs to build Vue assets too. Add to Build:
```
pnpm install --frozen-lockfile && pnpm run build
```

before the composer install above.

### Step 1.6 — Smoke test

Railway gives you a `*.up.railway.app` URL. Visit it:
- `/` should redirect to login or show the welcome page
- `/login` should render
- `/register` should accept a fresh account

If you see a 500, check Railway logs.

### Day 1 deliverable

Live URL where the dashboard responds with HTTPS. You can sign up as a fake customer. No domain yet — that's Day 2.

---

## Day 2 — Domain + email (2-3 hours)

### Step 2.1 — Buy `flowstack.com` (or your brand)

- https://dash.cloudflare.com → Domains → Register (~$10/yr)
- Or transfer in from your registrar of choice

### Step 2.2 — Point DNS at Railway

Railway dashboard → Settings → Custom Domain:
- Add `app.flowstack.com`
- Railway shows you a CNAME target
- In Cloudflare DNS: CNAME `app` → that target
- Railway auto-issues SSL via Let's Encrypt (~2 min)

After SSL is live:
- Update Railway env: `APP_URL=https://app.flowstack.com`
- Redeploy

### Step 2.3 — Sign up for Resend (transactional email)

- https://resend.com → free 100 emails/day, $20/mo for 50k
- Create API key → paste into Railway env as `RESEND_API_KEY` (the variable the Laravel config actually reads — see `config/services.php` `'resend' => ['key' => env('RESEND_API_KEY')]`)
- Add domain: `flowstack.com`
- Resend shows DNS records: add to Cloudflare:
  - SPF: `TXT @ "v=spf1 include:_spf.resend.com ~all"`
  - DKIM: 2 CNAMEs Resend provides
  - DMARC (optional but recommended): `TXT _dmarc "v=DMARC1; p=quarantine"`
- Wait for Resend to verify (~5 min)

### Step 2.4 — Install the Resend Laravel driver

In your repo:
```bash
composer require resend/resend-php resend/resend-laravel
```

Add to `config/mail.php`:
```php
'mailers' => [
    'resend' => ['transport' => 'resend'],
    // ... existing mailers
],
```

Commit + push → Railway redeploys.

### Step 2.5 — Send a test mail

```bash
# In Railway's web shell:
php artisan tinker --execute="\Mail::raw('test from prod', fn(\$m) => \$m->to('you@youremail.com')->subject('Flowstack mail check'));"
```

Verify inbox. If it lands in spam: SPF/DKIM not yet propagated, wait 15 min and retry.

### Day 2 deliverable

`https://app.flowstack.com` resolves. Emails land in inboxes from `hello@flowstack.com`.

---

## Day 3 — Plan rename + landing handoff (3 hours)

Done in this commit. Already aligned with `flowstack.com/pricing`:
- Starter: $99/mo, 1 agent, 2,500 credits/mo
- Operator (Plan::Pro): $399/mo, 5 agents, 25,000 credits/mo
- Custom (Plan::Business): scoped project, contact sales

### Step 3.1 — Update landing's registerUrl

In `/home/theone/automation-landing/src/lib/dashboard.ts`:
```ts
export function registerUrl() {
  return 'https://app.flowstack.com/register';
}
```

Deploy the landing to its own host (Vercel, Netlify). Set `flowstack.com` apex domain to point at the landing; `app.flowstack.com` already points at the dashboard.

### Day 3 deliverable

Landing's "Try it for $99" button leads to your dashboard's register page.

---

## Day 4 — Stripe Invoices (manual flow, 2 hours)

**Skip Stripe Checkout integration for v1.** Use Stripe's hosted Invoice product instead — zero code on your side.

### Step 4.1 — Stripe setup

- https://dashboard.stripe.com → Sign up (or use existing account)
- Activate live mode (real card payments) — requires bank account + business details
- Create 3 Products:
  - Starter Plan — recurring monthly, $99/mo
  - Operator Plan — recurring monthly, $399/mo
  - Custom Build — one-time, "Let's talk" (price set per-invoice)

### Step 4.2 — Onboarding flow (manual, white-glove)

When someone signs up on your dashboard:
1. They land on `/onboarding/intro` (existing flow)
2. They click "Get started"
3. Onboarding creates their team + sets `plan = Plan::Free` (no agent yet — pool not allocated)
4. They land on `/billing` which shows "your plan: Starter — pay $99/mo to activate"
5. You get an email notification of the new signup (queue a `NewSignupNotification` to platform admins)

You manually:
1. Open Stripe dashboard → New Invoice
2. Customer email = their signup email
3. Add line item: Starter Plan $99
4. Send invoice (Stripe emails them a hosted payment link)

When they pay, Stripe sends you an email. You:
1. SSH into Railway, run:
```bash
php artisan tinker
```
```php
$team = App\Models\Team::where('user_id', User::where('email', 'their@email.com')->first()->id)->first();
$team->forceFill(['plan' => App\Billing\Plan::Free->value])->save();
(new App\Billing\CreditMeter)->grantMonthlyRenewal($team->fresh());
// Allocate pool entry → activates agent
$agent = $team->fresh()->currentAgent;
if (!$agent) {
    $entry = (new App\Provisioning\PoolAllocator)->allocate(
        App\Models\Agent::factory()->for($team)->create(['status' => 'draft'])
    );
}
```

Send them a "You're live!" email manually (or queue a `CustomerActivatedNotification`).

### Day 4 deliverable

A repeatable manual flow to take real money. Stripe handles tax, refunds, dunning. You handle provisioning by tinker (~5 min per customer).

This is "good enough for first 10 customers." Automate after that.

---

## Day 5 — Seed the Voiceflow pool (30 min)

Pre-create 5 empty Voiceflow projects to allocate to your first 5 customers.

### Step 5.1 — Voiceflow project creation (browser)

For each of 5 projects:
1. https://creator.voiceflow.com → New project
2. Template: "Start from scratch" or "Lead Qualification" (the latter has Capture blocks built in)
3. Name: `flowstack-customer-001`, ..., `flowstack-customer-005`
4. Get the DM API key (Settings → API)
5. Get the project ID (from the URL)

### Step 5.2 — Seed the pool

```bash
php artisan tinker
```

```php
$pool = [
    ['id' => '...', 'dm' => 'VF.DM....'],
    ['id' => '...', 'dm' => 'VF.DM....'],
    ['id' => '...', 'dm' => 'VF.DM....'],
    ['id' => '...', 'dm' => 'VF.DM....'],
    ['id' => '...', 'dm' => 'VF.DM....'],
];

$workspaceKey = config('services.voiceflow.workspace_api_key');

foreach ($pool as $p) {
    App\Models\VoiceflowProjectPoolEntry::create([
        'voiceflow_project_id' => $p['id'],
        'voiceflow_api_key' => $p['dm'],
        'voiceflow_workspace_api_key' => $workspaceKey,
        'voiceflow_environment' => 'main',
        'status' => 'available',
    ]);
}

echo App\Models\VoiceflowProjectPoolEntry::where('status', 'available')->count() . ' available' . PHP_EOL;
```

### Step 5.3 — Verify

```php
App\Models\VoiceflowProjectPoolEntry::all()->each(fn($e) => print("{$e->voiceflow_project_id}: {$e->status}\n"));
```

Should print 5 rows, all `available`.

### Day 5 deliverable

Pool is stocked. Next customer signup auto-allocates one entry (when you manually run the provisioning tinker line).

---

## Day 6-7 — Smoke test + buffer (4-6 hours)

Pretend to be a customer. Sign up at https://app.flowstack.com/register with a different email. Walk through:

1. ✅ Register → email verification (if enabled) → land on dashboard
2. ✅ Onboarding wizard runs
3. ✅ Manually invoice + pay yourself $99 via Stripe (test card `4242 4242 4242 4242`)
4. ✅ Run the tinker provisioning line as if you were the operator
5. ✅ Refresh dashboard → see your agent active
6. ✅ Open /chat → have a real conversation with your "Auto" or test pool project
7. ✅ Capture a lead → see it on /leads
8. ✅ Open /system/architecture → flowcharts render
9. ✅ Verify credit-burn alert email arrives when balance hits 50% of grant

If anything's broken, fix it. Welcome to launch week.

---

## Total cost-to-launch summary

| Item | Cost |
|---|---|
| Voiceflow Pro upgrade | $60 / mo |
| Railway (hosting + Postgres) | ~$10-20 / mo |
| Cloudflare domain | ~$10 / year |
| Resend (transactional email) | Free up to 100/day |
| Stripe | 2.9% + 30¢ per transaction (no fixed fee) |
| **Total fixed monthly** | **~$71 / mo** |
| Break-even | Customer #1 ($99 > $71) |

## What's deferred to "later"

These are real gaps but don't block first revenue:

- Self-serve Stripe Checkout (use Invoices until customer #10)
- Lead detail page
- Real RBAC
- Bulk actions on kanban
- Search/filter
- Mobile polish
- Slack/HubSpot integrations
- Onboarding checklist widget
- Pool watermark alerts (eyeball weekly)
- Email verification flow polish
- Forgot-password flow polish

You ship these AFTER 3 customers have asked for them. Don't build for users who don't exist yet.
