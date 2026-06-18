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
ssh -i ~/.ssh/id_ed25519_forge forge@<server-ip>
```

Site path on the box:

```
/home/forge/automation_dashboard-ieqy3g2b.on-forge.com/current
```

Get `<server-ip>` from the server page: https://forge.laravel.com/servers

## Verify the scheduler is on (one-liner)

```bash
ssh -i ~/.ssh/id_ed25519_forge forge@<server-ip> 'cd /home/forge/automation_dashboard-ieqy3g2b.on-forge.com/current && crontab -l | grep schedule:run; php artisan schedule:list'
```

- A `* * * * * ... artisan schedule:run` line present → cron is installed.
- Nothing → enable Forge → Server → **Scheduler** (`php artisan schedule:run`, every minute).

Proof it's actually firing (recent timestamp = live):

```bash
ssh -i ~/.ssh/id_ed25519_forge forge@<server-ip> 'cat /home/forge/automation_dashboard-ieqy3g2b.on-forge.com/current/data/agents/system-check/findings.json'
```
