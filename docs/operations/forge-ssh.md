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
  definitions — it proves nothing about cron). Reinstall the line below.

The installed line (2026-07-29, after the crontab was found empty —
plausibly lost in the site-dir migration) overwrites a one-run log each
minute, so the file's mtime is a permanent liveness probe:

```
* * * * * php /home/forge/app.flowstack.run/current/artisan schedule:run > /home/forge/app.flowstack.run/schedule-last.log 2>&1
```

Proof it's actually firing — mtime within the last minute:

```bash
ssh -i ~/.ssh/id_ed25519_forge forge@178.128.138.142 'date; ls -la /home/forge/app.flowstack.run/schedule-last.log'
```

Note: `data/agents` (the collectors' findings tree) sits inside the
release dir — not a Forge shared path — so it resets on every deploy;
don't read its absence right after a deploy as a dead scheduler. A dead
scheduler silences credit renewals, ledger reconcile, spend-check, lead
nudges, the weekly digest and provider health checks.
