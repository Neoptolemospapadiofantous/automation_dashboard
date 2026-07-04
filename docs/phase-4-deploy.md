# Phase 4 — Deploy command (push + Laravel Forge)

A one-command deploy: push already-made commits, then trigger a Laravel Forge
deployment.

## What this phase delivers

- **`bin/deploy.sh`** — pushes the current branch (existing commits only; it
  never commits for you), with exponential-backoff retries on transient network
  failures and a dirty-tree guard, then pings the Forge **deploy hook** so Forge
  runs the server-side deploy script.
  - Flags: `--no-deploy` (push only), `--branch <name>`, `--status` (poll the
    Forge API until the deploy finishes/fails).
- **`deploy/forge-deploy.sh`** — the server-side deploy script template to paste
  into the Forge site (pull → composer → pnpm build → migrate → cache →
  `queue:restart` → FPM reload).
- **`.forge-deploy.example`** — template for the secret hook URL. The real
  `.forge-deploy` is **gitignored**, so the hook never enters git. The script
  also accepts `FORGE_DEPLOY_HOOK` from the environment.
- **`composer deploy`** alias.

## Usage

```bash
bin/deploy.sh                 # push current branch + trigger Forge deploy
composer deploy               # same, via composer
bin/deploy.sh --no-deploy     # push only
bin/deploy.sh --branch main   # push a specific branch
bin/deploy.sh --status        # poll Forge for the deploy result
```

## One-time setup

1. Paste `deploy/forge-deploy.sh` into the Forge site's **Deploy Script** box;
   set the site's Git branch; add a queue worker daemon ([[phase-2-realtime|live broadcasts]] need
   it).
2. `cp .forge-deploy.example .forge-deploy` and paste the site's **Deploy Hook**
   URL (Forge → Site → Apps → "Deploy Hook"). Or export `FORGE_DEPLOY_HOOK`.
3. *(Optional, for `--status`)* export `FORGE_API_TOKEN`, `FORGE_SERVER_ID`,
   `FORGE_SITE_ID`.

## Secret handling

The deploy hook URL is a secret and is never committed — `git check-ignore`
confirms `.forge-deploy` is ignored; only `.forge-deploy.example` is tracked.

## Live behaviour (verified 2026-07-04)

The prod site has Forge **Quick Deploy** enabled, so **a plain push to `main`
already triggers the deploy** — Forge runs the server-side script on the push
webhook. `bin/deploy.sh` does the right thing either way:

- With no `.forge-deploy` file (and `FORGE_DEPLOY_HOOK` unset), it pushes, prints
  `! No Forge deploy hook configured — pushed, but did not trigger a deploy`, and
  exits 0. **The deploy still happens** via Quick Deploy. The hook is only needed
  to trigger a deploy *without* pushing, or to use `--status` polling.
- `main` is a protected branch (14 required checks); the Hermes pre-push audit
  gate runs first and the push reports `Bypassed rule violations` — expected.
- `public/build` is gitignored, so Forge rebuilds assets server-side; never
  commit built assets.

To confirm a deploy landed **without SSH**, fetch a public asset over HTTPS and
grep for a change marker, e.g.
`curl https://app.flowstack.run/widget/<slug>.js | grep <marker>`.
