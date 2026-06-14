# Reverb in production (Forge) — runbook

Realtime is **Laravel Reverb** (self-hosted, first-party). The browser connects
to **`wss://ws.flowstack.run`**; nginx terminates TLS and proxies to the Reverb
server running on `127.0.0.1:8080`. Broadcasts are **queued**, so a queue worker
must also run.

```
browser ──wss──► ws.flowstack.run (nginx :443, TLS) ──http──► 127.0.0.1:8080 (reverb:start)
app  ──broadcast()──► queue (database) ──► queue:work ──► Reverb ──► browser
```

App host: `app.flowstack.run` · websocket host: `ws.flowstack.run`.

---

## 1. Generate fresh prod Reverb keys
Do **not** reuse the local dev keys. On the server (or anywhere), generate three
values:
```bash
php -r 'echo "REVERB_APP_ID=".random_int(100000,999999).PHP_EOL;'
php -r 'echo "REVERB_APP_KEY=".bin2hex(random_bytes(16)).PHP_EOL;'
php -r 'echo "REVERB_APP_SECRET=".bin2hex(random_bytes(16)).PHP_EOL;'
```

## 2. Forge `.env` (set BEFORE the deploy build — `VITE_REVERB_*` bake into JS)
```bash
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=<from step 1>
REVERB_APP_KEY=<from step 1>
REVERB_APP_SECRET=<from step 1>

# What the browser connects to (public, TLS-terminated by nginx):
REVERB_HOST=ws.flowstack.run
REVERB_PORT=443
REVERB_SCHEME=https

# Where the Reverb process binds locally (behind the proxy):
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080
```
`config/broadcasting.php` (host/port/scheme) and `resources/js/echo.js`
(`VITE_REVERB_*`) already read these — no code change needed.

## 3. DNS
- [ ] `ws.flowstack.run` → **A/AAAA** record pointing at the **same server** as
      `app.flowstack.run`.

## 4. nginx — the `ws.flowstack.run` site (Forge)
1. Forge → **New Site** → `ws.flowstack.run` (any project type; the document
      root is irrelevant — all traffic is proxied). Let Forge issue **Let's
      Encrypt TLS** for it.
2. Forge → that site → **Edit Nginx Configuration** → replace the `location /`
      block with a websocket proxy to Reverb:

```nginx
location / {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;

    # WebSocket upgrade — required, or the connection 400s/closes.
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";

    proxy_read_timeout 600s;
    proxy_send_timeout 600s;
}
```
3. Save → Forge reloads nginx.

> Keep TLS on the proxy (nginx), not on Reverb. Reverb stays plain HTTP on
> `127.0.0.1:8080` — never expose `8080` publicly.

## 5. Forge daemons
Add under Forge → Server (or Site) → **Daemons** (run as user `forge`,
directory = the site path):

- [ ] **Reverb server**
  ```
  php artisan reverb:start --host=0.0.0.0 --port=8080
  ```
- [ ] **Queue worker** (broadcasts + queued mail/notifications)
  ```
  php artisan queue:work --tries=3 --max-time=3600 --sleep=1
  ```

(Both run under supervisor; Forge restarts them on crash.)

## 6. Scheduler
- [ ] Forge → Site → **Scheduler** → enable (adds `* * * * * php artisan schedule:run`).
      Separate from realtime, but required for the daily jobs (audit sentinel,
      credit renewals/reconcile, spend-check) — see the launch checklist.

## 7. Reload Reverb on deploy
Append to the Forge **deploy script** (after `migrate`/`build`) so the running
server picks up new code/config:
```bash
$FORGE_PHP artisan reverb:restart
```

## 8. Verify
- [ ] Daemons up: Forge daemon panel shows both green, or on the box:
      `sudo supervisorctl status | grep -E 'reverb|queue'`
- [ ] Reverb reachable through nginx:
      `curl -sI https://ws.flowstack.run` → a response (not a connection error)
- [ ] Browser: open `app.flowstack.run`, DevTools → Network → WS — a connection
      to `wss://ws.flowstack.run/app/<REVERB_APP_KEY>...` shows **101 Switching
      Protocols**; the dashboard pill reads **"Live"**.
- [ ] End-to-end: capture/move a lead in one tab → the board updates in another
      tab without refresh.

## Troubleshooting
- **"Offline" pill / WS fails to connect** → check the `ws.flowstack.run` cert is
  valid, DNS resolves to the right box, and the nginx `Upgrade`/`Connection`
  headers are present.
- **Connects but no live updates** → the **queue worker** isn't running
  (broadcasts sit in the `jobs` table). Start/restart it.
- **403 on subscribe to private channels** → app + browser must share the same
  `REVERB_APP_KEY`; rebuild the frontend after changing `.env` (the key is baked
  into JS at build time).
- **Port 8080 publicly reachable** → it shouldn't be; firewall it / bind only
  behind nginx.
