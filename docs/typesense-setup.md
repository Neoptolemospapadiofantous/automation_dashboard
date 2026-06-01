# Typesense setup (fast + semantic conversation search)

Conversation search works out of the box on a **DB fallback** (no service
needed). To enable fast, typo-tolerant **and semantic** search at scale, run
the open-source [Typesense](https://typesense.org) engine and point Scout at it.

Typesense is GPL-v3 open source and self-hosts on your existing server — no
license fee, no third-party data sharing (fits the data-ownership model).

## 1. Run Typesense on the server

**Docker (simplest):**

```bash
docker run -d --name typesense --restart unless-stopped \
  -p 8108:8108 \
  -v /var/lib/typesense:/data \
  typesense/typesense:27.1 \
  --data-dir /data \
  --api-key='CHOOSE_A_STRONG_KEY' \
  --enable-cors
```

**Or as a Forge daemon** (Server → Daemons): run the `typesense-server` binary
with `--data-dir /var/lib/typesense --api-key=... --listen-port 8108`.

Semantic search uses Typesense's built-in embedding model
(`ts/all-MiniLM-L12-v2`), downloaded automatically on first use — no external
embedding API or key required.

## 2. Configure the app

In the Forge site's **Environment** (`.env`):

```env
SCOUT_DRIVER=typesense
SCOUT_QUEUE=true
TYPESENSE_API_KEY=CHOOSE_A_STRONG_KEY   # must match the server's --api-key
TYPESENSE_HOST=127.0.0.1
TYPESENSE_PORT=8108
TYPESENSE_PROTOCOL=http
```

## 3. Deploy

The deploy script (`deploy/forge-deploy.sh`) detects `SCOUT_DRIVER=typesense`
and automatically runs:

```bash
php artisan scout:sync-index-settings      # creates/updates the collection schema
php artisan scout:import "App\Models\Message"   # back-fills existing messages
```

A running **queue worker** is required (`SCOUT_QUEUE=true` indexes via the
queue) — you already need it for broadcasts.

## 4. Verify

```bash
# On the server:
curl http://127.0.0.1:8108/health -H "x-typesense-api-key: CHOOSE_A_STRONG_KEY"
# -> {"ok":true}

php artisan tinker --execute="echo App\Models\Message::search('hello')->take(1)->get()->count();"
```

Then use the **Conversations → Search** page: queries now match by keyword
*and* meaning (hybrid). Drop `SCOUT_DRIVER` (or set it empty) to fall back to
the DB scan at any time.

## Notes

- The collection schema (incl. the embedding field) lives in
  `config/scout.php` under `typesense.model-settings`.
- Re-import after large data changes: `php artisan scout:import "App\Models\Message"`.
- Flush the index: `php artisan scout:flush "App\Models\Message"`.
