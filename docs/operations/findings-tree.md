# The findings tree (§3.1) — database store and grid feed

The dashboard's health collectors — `providers:health-check` (hourly) plus the
three bash agents `audit_sentinel.sh` (daily), `system_check.sh` (6-hourly) and
`update_inspector.sh` (weekly) — each write `data/agents/<collector>/findings.json`.
That path sits **inside the release directory on Forge**, so every deploy resets
it, and the grid tooling on the studio box reads its own copy. The durable
record is therefore the **`agent_findings` table**, owned by
`app/Support/Findings/FindingsStore`:

- `record(collector, payload)` — one row per run, keyed by the collector's own
  `ts` (never inserted_at, so a stale run reads as stale). Idempotent: the same
  (collector, ts) is a no-op. `providers:health-check` writes here directly;
  `findings:ingest` (every 15 min) picks up the bash agents' files unchanged.
- `latest()` — newest run per collector, payload passed through untouched. The
  §3.1 shapes differ per collector and carry no schema field; nothing in this
  store interprets them.
- `prune()` — 30 days, never below 50 rows per collector, run inside
  `findings:ingest`.

`GET /api/findings` (`FindingsController`) serves `latest()` as
`{ts, collectors: {name: {ts, overall, payload}}}` behind bearer
`FINDINGS_READ_TOKEN` — 503 while unset, 401 on mismatch, 60/min. The consumer
is the grid's `grid-findings` mirror (cron :05/:20/:35/:50 on the studio box),
which writes the four dashboard-owned collectors back into its local
`data/agents/` tree for grid-control / grid-sentinel / grid-live. An empty
tree serialises as `{}` (the mirror indexes by name). Two client traps:
Cloudflare 403s python-urllib's default User-Agent before Laravel sees the
request, and the payload is re-encoded JSON — semantically identical to the
file, not byte-identical.

Files keep being written exactly as before; nothing that reads them changed.
Tests: `tests/Feature/FindingsStoreTest.php`.
