# Forge SSH — Hermes key

Dedicated key for SSH'ing into the Forge server that hosts `automation_dashboard`.

## Public key (paste into Forge → Server → SSH Keys)

- **Name:** `Hermes`
- **Key:**

```
ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIN+P+W5utlkTcuvDPIh6jvgZByE3TU06PcRfPiSHqr5w forge-hermes-automation_dashboard
```

## Key files (this machine)

| File | Role |
|---|---|
| `~/.ssh/id_ed25519_forge` | private key — never share |
| `~/.ssh/id_ed25519_forge.pub` | public key (the line above) |

Comment `forge-hermes-automation_dashboard` keeps it distinct from the `oci-rithmic` key (other project). No passphrase.

## Connect

```bash
ssh -i ~/.ssh/id_ed25519_forge forge@178.128.138.142
```

Site path on the box (zero-downtime releases — `current` is a symlink; the
old `automation_dashboard-ieqy3g2b.on-forge.com` dir is gone):

```
/home/forge/app.flowstack.run/current
```

Server: graceful-helsinki (178.128.138.142) — confirm on the server page if
it ever moves: https://forge.laravel.com/servers

## Verify the scheduler is on (one-liner)

```bash
ssh -i ~/.ssh/id_ed25519_forge forge@178.128.138.142 'cd /home/forge/app.flowstack.run/current && crontab -l | grep schedule:run; php artisan schedule:list'
```

- A `* * * * * ... artisan schedule:run` line present → cron is installed.
- Nothing → the scheduler is NOT running (`schedule:list` only prints the
  definitions — it proves nothing about cron). Enable Forge → Server →
  **Scheduler** (`php artisan schedule:run`, every minute) or install the
  forge-user crontab line by hand.

Proof it's actually firing — a fresh timestamp in the system-check
collector's report:

```bash
ssh -i ~/.ssh/id_ed25519_forge forge@178.128.138.142 'cat /home/forge/app.flowstack.run/current/data/agents/system-check/findings.json'
```

Caveat: `data/` sits inside the release dir (it is not a Forge shared
path), so this file only exists once the six-hourly `system_check.sh`
slot has fired since the LAST DEPLOY. Missing right after a deploy is
normal; still missing 6+ hours later means the scheduler is dead —
which also silences credit renewals, ledger reconcile, spend-check,
lead nudges, the weekly digest and provider health checks.
