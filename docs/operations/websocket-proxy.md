# Websocket proxy — `ws.flowstack.run` → Reverb

The dashboard's realtime features (broadcast over Laravel Reverb) are served
from a dedicated subdomain, `ws.flowstack.run`, which reverse-proxies to the
Reverb server running locally on the prod box (`127.0.0.1:8080`).

This is configured once in Forge's Nginx editor; the running config then lives
on the server. Re-apply the steps below only when rebuilding the server or the
site.

## Forge setup

1. Forge → the `ws.flowstack.run` site → **Edit Nginx Configuration**.
2. Replace the **entire** config with the block below. The only change from
   Forge's default is that the usual `include .../site.conf;` line is swapped
   for the `location /` Reverb proxy block.
3. Save — Forge reloads Nginx.

The Forge IDs and SSL cert paths below are specific to the current server; if
Forge regenerates them, copy the matching `include` / `ssl_certificate` lines
from the site's *default* config before pasting.

```nginx
# FORGE CONFIG (DO NOT REMOVE!)
include forge-conf/3244523/before/*;
include forge-conf/3244523/ws.flowstack.run/before/*;

server {
    http2 on;
    listen 443 ssl;
    listen [::]:443 ssl;
    server_name ws.flowstack.run;
    server_tokens off;
    root /home/forge/ws.flowstack.run/public;

    # FORGE SSL (DO NOT REMOVE!)
    ssl_certificate /etc/nginx/ssl/domains/1722345/3524682/server.crt;
    ssl_certificate_key /etc/nginx/ssl/domains/1722345/3524682/server.key;

    # Reverse-proxy everything to the Reverb websocket server (127.0.0.1:8080).
    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_read_timeout 600s;
        proxy_send_timeout 600s;
    }
}

# FORGE CONFIG (DO NOT REMOVE!)
include forge-conf/3244523/after/*;
include forge-conf/3244523/ws.flowstack.run/after/*;
```

## Notes

- The `Upgrade` / `Connection "Upgrade"` headers are what allow the websocket
  handshake through the proxy — without them the connection downgrades to plain
  HTTP and Reverb clients fail to connect.
- The 600s read/send timeouts keep long-lived idle websocket connections from
  being culled by Nginx.
- Reverb itself runs as a separate daemon on the box; this only handles the TLS
  termination + proxy hop.
