# Architecture — public stats data flow (dashboard side)

How `/api/public/stats` answers a request. Reference for changes to the
controller, cache strategy, or `platform_settings` schema.

> Contract: [[public-surface|../public-surface.md]]
> Landing-side companion: [[landing-sse-pipeline|./landing-sse-pipeline.md]]

## High-level

```mermaid
flowchart LR
  client[HTTP client<br/>landing-site proxy or scraper]
  route[api.php<br/>throttle:60,1]
  ctrl[PublicStatsController::show]
  cache[(Laravel cache<br/>key=public_stats<br/>TTL=300s)]
  compute[compute method]
  bucket[bucket + formatBucket]

  settings[(platform_settings table)]
  teams[(teams table)]
  agents[(agents table)]
  leads[(leads table)]
  messages[(messages table)]

  client -->|GET /api/public/stats| route
  route --> ctrl
  ctrl -->|hit| cache
  cache -->|miss| compute
  compute --> settings
  compute --> teams
  compute --> agents
  compute --> leads
  compute --> messages
  compute --> bucket
  bucket --> compute
  compute -->|store| cache
  cache -->|JSON + CORS headers| client
```

## Request sequence (cache miss)

```mermaid
sequenceDiagram
  participant C as Client
  participant R as Route handler
  participant K as Cache::remember
  participant M as compute()
  participant DB as Database
  participant B as bucket()

  C->>R: GET /api/public/stats
  R->>K: remember("public_stats", 300, compute)
  K->>M: cache miss → invoke
  M->>DB: PlatformSetting::value(key, default) ×4
  M->>DB: Team::count()
  M->>DB: Agent::where(status=active)->count()
  M->>DB: Lead::count()
  M->>DB: Lead::where(status=qualified)->count()
  M->>DB: Message::count()
  M->>DB: Message::where(created_at >= now-24h)->count()
  Note over M: time_saved_hours derived from messages_handled (no extra query)
  M->>DB: Lead::where(status=qualified)->latest()->value(created_at)
  M->>B: for each count → bucket(n)
  B-->>M: "10+" | "100+" | ... | null
  Note over M: last_activity_at passes through un-bucketed
  M-->>K: assembled payload
  K-->>R: payload
  R-->>C: 200 JSON + CORS + Cache-Control
```

## Request sequence (cache hit — the common case)

```mermaid
sequenceDiagram
  participant C as Client
  participant R as Route handler
  participant K as Cache::remember
  C->>R: GET /api/public/stats
  R->>K: remember("public_stats", 300, ...)
  K-->>R: cached payload (no DB hit)
  R-->>C: 200 JSON
```

After the first miss, every subsequent request for the next 5 minutes
returns from cache — zero DB queries. Throughput is bounded by HTTP
plumbing, not by the database.

## Cache invalidation

```mermaid
flowchart TB
  cli[php artisan platform:set]
  put[PlatformSetting::put]
  forget[Cache::forget public_stats]
  next[Next /api/public/stats hit]

  cli --> put
  put -->|UPDATE platform_settings| put
  put --> forget
  forget --> next
  next -.->|cache miss<br/>fresh compute| next
```

Operator edits land immediately because `PlatformSetting::put()` forgets
the cache key in the same call. The next request recomputes. Aggregate
counts that change "organically" (a new team, a qualified lead) do **not**
bust the cache — they're absorbed by the 5-minute TTL. Landing-site
visitors see those changes on the next 5-min boundary.

## Why these counts and not others

The exposed counts (`teams_count`, `agents_active`, `leads_total`,
`leads_qualified`, `messages_handled`, `messages_last_24h`,
`time_saved_hours`) plus the un-bucketed `last_activity_at` recency
field were chosen because:

- They tell a coherent funnel story (signups → agents → leads → qualified → engagement) plus recency / "is it being used right now" signals
- Each is a single indexed `COUNT(*)` or a `latest()->value()` — cheap to compute on every cache miss
- `time_saved_hours` is purely derived from `messages_handled` (no extra query)
- None contain per-tenant detail
- Bucketing masks small early-stage values automatically; `last_activity_at` is rendered as relative time on the landing client so a single recent timestamp is not identifying

Candidates intentionally NOT exposed:
- `users.count()` — duplicates `teams_count` for our model (each user gets a personal team on signup)
- `conversations.count()` — engagement signal is better served by `messages_handled`
- `voiceflow_project_pool` availability — operational signal, not public

To add another: see [../public-surface.md#adding-a-new-computed-field](../public-surface.md#adding-a-new-computed-field).
