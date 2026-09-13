# Team settings: notifications, business hours, webhooks, weekly report, tags, CSV, KB refresh

Seven features shipped together on 2026-09-13 to round out the App as a
self-serve product. None changes a §3 contract; all live behind the
existing auth group. This page is the operator reference.

## Notification preferences (per user)

- Column `users.notification_preferences` (JSON, null = defaults).
- `App\Support\NotificationPreferences` applies defaults and filters a
  notification's channel list. Every `Notification::via()` calls
  `$notifiable->notificationPreferences()->filter('<event>', [...])`.
- Events: `handoff` (bell/mail/call), `lead_captured`, `lead_assigned`,
  `follow_up`, `credits` (bell/mail), `weekly_digest` (mail).
- Quiet hours drop mail and the call; the bell (database channel) is never
  quiet, so nothing is lost. Window may cross midnight; evaluated in the
  user's chosen timezone.
- `teams:weekly-digest` skips owners who switched the digest off.
- Page: `/settings/notifications` (`NotificationPreferencesController`).

## Business hours (per team)

- Column `teams.business_hours` (JSON, null/disabled = always open).
- `App\Support\BusinessHours`: per-day `[open, close]` windows or null,
  timezone, away message (default in `DEFAULT_AWAY`), `isOpen()`,
  `nextOpening()`, `awayLine()`.
- `EscalateToHuman::handle()` sets `meta.handoff_out_of_hours` and passes
  `ring: false` to `HandoffRequestedNotification` when closed — the bell
  and email still land, the phone stays quiet. `FlowExecutor` appends the
  away line to any escalated reply; `RequestHandoffTool` tells the model
  not to promise "right away".
- `meta.handoff_out_of_hours` is the routing signal for a future
  out-of-hours voice agent.
- Page: `/settings/hours` (`BusinessHoursController`, owner writes).

## Outbound webhooks (per team, paid plans)

- Table `team_webhooks`: url, encrypted secret (hidden), events JSON,
  active, last_status/last_error/last_delivered_at, failure_count.
- Events: `lead.captured` (chat capture + hand-created; NOT CSV import),
  `handoff.requested`, `conversation.ended` (Conversation `updated`
  observer on status → ended). Payload shapes in `App\Support\WebhookPayloads`.
- `App\Services\WebhookDispatcher::dispatch(team, event, data)` queues one
  `App\Jobs\DeliverWebhook` per subscribed endpoint. 3 tries, backoff
  30 s / 5 min; header `X-Flowstack-Signature: t=<unix>,v1=<hmac-sha256 of "t.body">`;
  URL SSRF-checked at save and again at delivery via `PublicWebPage`.
  25 consecutive failures switch the endpoint off (re-enable resets).
- Page: `/settings/webhooks` (`WebhookController`, owner writes). The
  secret is shown once via the `webhook_secret` session flash.

## Shareable weekly report

- Column `teams.report_token` (null = off). Public page `/report/{token}`
  (Blade `report/weekly`, no auth, noindex) renders `App\Support\WeeklyReport::stats()`
  live for the last 7 full days — the same block `teams:weekly-digest` mails,
  which now also carries the link and a "pages re-read" line.
- Page: `/settings/report` (`WeeklyReportController`): enable / rotate / disable.

## Tags and labels

- `leads.tags`, `conversations.labels` (JSON arrays). Normalised by
  `App\Support\Tags` (lower-case, trimmed, deduped, ≤20 × ≤40 chars) in
  the model mutators. Filter with `?tag=` on the board and `?label=` on the
  conversation list (`whereJsonContains`). `PATCH /leads/{lead}/tags`,
  `PATCH /conversations/{conversation}/labels` replace the whole list.

## Lead CSV export / import

- `GET /leads/export` streams the board with the current filters
  (`App\Support\LeadCsv::COLUMNS`; formula-injection cells are prefixed).
- `POST /leads/import` (≤2 MB, ≤5,000 rows, lenient headers): match by
  email; an existing lead keeps status and score and only fills blanks,
  tags merge. No owner notification, no webhook, no broadcast per row.

## Knowledge base URL refresh

- `knowledge:refresh-urls` weekly (Sunday 03:30) re-fetches every
  `source=url` document; re-ingests only when the text hash changed
  (`KnowledgeBase::ingestDocument` replaces the row by `source_url`).
  Columns `kb_documents.refresh_checked_at / refreshed_at / refresh_error`;
  a failed fetch keeps the old content in service.
- `POST /knowledge/{id}/refresh` runs the same code for one document
  (the Refresh button); the Knowledge page shows the last outcome.
