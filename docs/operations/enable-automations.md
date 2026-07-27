# Enabling Automations (n8n prod → Activity)

How to take the automation surface — the **Actions** editor, the agent's
`call_automation` tool, and the read-only **Activity** run-log — from "built
but dark" to live in production.

> **Why it's dark today.** Everything is deployed and routed; the surface is
> gated on the `RUNTIME_AUTOMATION_ENABLED` flag, which is unset (false) in
> prod. The nav's Activity link renders on the `automationsEnabled` Inertia
> prop, so it stays hidden until the flag flips. But the flag is not the real
> blocker — **there is no prod n8n for the agent to call.** The
> `flowstack-n8n` prod stack has never been deployed (blocked on the
> `n8n.flowstack.run` DNS record + a VM). Flip the flag without that and you
> get an empty Activity page and a `call_automation` tool that points at
> nothing. Do Part A first.

The two parts are independent projects — Part A is `~/flowstack-n8n`, Part B is
this repo. Both are needed before Activity shows real rows.

---

## Part A — bring up n8n in production (`~/flowstack-n8n`)

The prod stack is queue-mode: `n8n-main` + `n8n-worker` + `n8n-webhook`,
Postgres 16 + Redis 7, with **Caddy owning :80/:443** and auto-TLS for
`$N8N_HOST`. That last point is why n8n needs its **own box** — it cannot share
the Forge app server, whose nginx already owns 80/443.

1. **Provision a VM** separate from the Forge app box (Docker + Docker Compose).
2. **DNS** — add an A record `n8n.flowstack.run` → the VM's IP in the
   Cloudflare zone. *This is the standing blocker; nothing below works until it
   resolves.*
3. **Bootstrap the env** on the box:
   ```bash
   cd ~/flowstack-n8n && ./bootstrap.sh n8n.flowstack.run
   ```
   This writes a `0600 .env` with a freshly generated `N8N_ENCRYPTION_KEY` and
   `POSTGRES_PASSWORD`.
4. **Pin `N8N_VERSION`** in `.env` to a specific tag (it defaults to `latest` —
   never ship `latest`).
5. **Copy `N8N_ENCRYPTION_KEY` to an offsite secret store.** Losing or rotating
   it bricks every stored credential in n8n. `backup.sh` dumps Postgres only —
   the key is **not** in the backup by design.
6. **Start it:** `docker compose up -d` (uses `docker-compose.yml`, the prod
   file — *not* `docker-compose.local.yml`).
7. **Verify:** `https://n8n.flowstack.run/` loads the editor over valid TLS.
8. **Migrate the workflows.** The 11 live workflows exist **only in the local
   container's volume DB** (`flowstack-n8n-local`), not in git — export them
   from local and import into prod, or rebuild. Two repoint gotchas when they
   move to a cloud box (see SHARED.md §3.5/§3.10):
   - The LinkedIn workflows call the promoter webhook at `172.17.0.1:8088` and
     the bridge at `172.18.0.1:8765` — **host-bridge-local IPs a cloud n8n
     cannot reach.** Repoint or leave those workflows on the local instance.
   - `SLACK_ALERT_WEBHOOK_URL` must be passed through to the prod container the
     same way `docker-compose.local.yml` does, or error-alerts/digests go
     silent.

> The dashboard's Actions do **not** depend on those existing LinkedIn
> workflows — you create fresh webhook workflows in prod n8n for each Action
> (Part B, step 4). The migration above only matters if you also want the
> growth automations running from the cloud box.

---

## Part B — enable the surface in the dashboard (this repo)

Once `https://n8n.flowstack.run` answers:

1. **Set the flag** in the dashboard's Forge env:
   ```
   RUNTIME_AUTOMATION_ENABLED=true
   ```
2. **Leave the SSRF escape hatch off** — prod MUST keep
   `RUNTIME_AUTOMATION_ALLOW_PRIVATE=false` (unset is fine). It blocks
   loopback/private/link-local targets including the `169.254.169.254` cloud
   metadata endpoint. The prod n8n is a public HTTPS host, so no private
   allowance is needed. (Only local dev sets it true to reach `localhost:5678`.)
3. **Deploy / clear config cache** so the flag takes effect. On the next page
   load `automationsEnabled` flips true, which:
   - reveals the **Activity** link in the sidebar (both desktop + mobile), and
   - activates the **Actions** editor (operator-only — automations are a
     managed service, gated to the Hermes operator allowlist) and the agent's
     `call_automation` tool.
4. **Configure Actions per agent** (Actions editor). Each action carries:
   - `url` — the prod n8n webhook, e.g.
     `https://n8n.flowstack.run/webhook/<path>` (the SSRF guard vets it; 3xx
     redirects are rejected, and the connection is IP-pinned so DNS can't
     rebind between check and send).
   - `mode` — `sync` (answer woven into the reply, capped at
     `RUNTIME_AUTOMATION_SYNC_TIMEOUT`, default 6s) or `async` (fire-and-forget).
   - `creditCost`, and a JSON parameter schema (the model's only guide to the
     argument shape).
5. **Verify signatures on the n8n side.** Every call is HMAC-signed with the
   agent's `automation_secret`:
   - `X-Flowstack-Timestamp` — unix seconds, part of the signed material.
   - `X-Flowstack-Signature` — `sha256=` + hex HMAC of `{timestamp}.{rawBody}`.
   Reject requests whose timestamp is outside your replay window or whose
   signature doesn't match `hash_hmac('sha256', "{ts}.{body}", secret)`.

Once an agent actually fires an action, the dispatcher writes an
`automation_runs` row (status, http status, latency, credits charged, trimmed
request/response) — **that is what populates Activity.** Until a call happens,
Activity renders but lists nothing (expected, not a bug).

---

## Verifying it's live

- **Flag reached prod:** the public login page's Inertia payload shows
  `"automationsEnabled":true`:
  ```bash
  curl -s https://app.flowstack.run/login | grep -o 'data-page="[^"]*"' \
    | python3 -c "import sys,html,re;print(re.findall(r'\"automationsEnabled\":(true|false)', html.unescape(sys.stdin.read())))"
  ```
- **Nav:** the Activity link appears under Workspace for a signed-in user.
- **End to end:** trigger a configured action from a test chat, then open
  Activity — the run appears with a `success` status and non-zero latency.

## Rolling back

Set `RUNTIME_AUTOMATION_ENABLED=false` (or unset it) and redeploy. The Activity
link hides again, the Actions editor and `call_automation` tool stand down, and
existing `automation_runs` rows are retained (the view just becomes
unreachable via nav). Clean and reversible — nothing else keys on the flag.

---

## Related

- `docs/phase-16-automations.md` — the feature's design + backend internals.
- `docs/governed-delegation-phase1-2.md` — the direction this surface grows
  into (scope cards + approval gates on top of the same dispatcher).
- SHARED.md §3.10 (n8n runtime), §3.5 (promoter webhook coupling) — the
  cross-project contracts the migration must respect.
