# Slack bot (local-team, two-way) — setup

The agent can **manage Slack**: reply to `@mentions` and DMs through the LLM
runtime, run slash commands, post/thread, and administer channels. It runs over
**Socket Mode** — an *outbound* WebSocket the app opens to Slack — so there is
**no public inbound endpoint** and the whole thing stays fully local.

> **Local only by design.** `slack:listen` **refuses to run in production**
> (it's an internal local-team tool). The prod-safe one-way alert/digest lane
> (`SLACK_ALERT_WEBHOOK_URL` → `hermes:alert` / `slack:digest`) is separate and
> unaffected. Do **not** add `slack:listen` as a Forge/supervisor daemon.

## 1. Create the Slack app (manifest)

Create an app at <https://api.slack.com/apps> → "From an app manifest", paste:

```yaml
display_information:
  name: Flowstack Local Agent
features:
  bot_user:
    display_name: flowstack
    always_online: true
  slash_commands:
    - command: /agent
      description: Ask the agent a question (admins only)
      should_escape: false
    - command: /hermes-status
      description: Latest Hermes collector snapshot
      should_escape: false
    - command: /channel
      description: Channel admin — create/archive/topic (admins only)
      should_escape: false
oauth_config:
  scopes:
    bot:
      - app_mentions:read
      - chat:write
      - im:history
      - im:read
      - im:write
      - reactions:write
      - channels:manage
      - channels:read
      - groups:write
      - commands
settings:
  event_subscriptions:
    bot_events:
      - app_mention
      - message.im
  interactivity:
    is_enabled: true
  socket_mode_enabled: true
  org_deploy_enabled: false
```

With Socket Mode enabled, slash commands, interactivity, and events **all arrive
over the WebSocket** — no Request URLs needed.

## 2. Tokens & scopes

- **Bot token** (`xoxb-…`): *Install App* → copy "Bot User OAuth Token".
- **App-level token** (`xapp-…`): *Basic Information → App-Level Tokens* →
  generate one with the `connections:write` scope.

## 3. Local `.env`

```dotenv
SLACK_BOT_TOKEN=xoxb-…
SLACK_APP_TOKEN=xapp-…
SLACK_ADMIN_USERS=U0123ABC,U0456DEF   # Slack user IDs allowed to spend / admin
SLACK_TEAM_ID=1                        # local Team that owns billing for LLM turns
SLACK_AGENT_ID=                        # optional; blank → the team's active agent
```

`/agent` and `/channel …` are restricted to `SLACK_ADMIN_USERS`. Anyone can
`@mention` or DM the bot to get an answer, but only those turns bill `SLACK_TEAM_ID`.

## 4. Run it (locally)

```bash
php artisan slack:listen
```

It connects, auto-reconnects on drop, and stops cleanly on Ctrl-C. Then in Slack:

- `@flowstack what changed in the widget?` → threaded LLM reply
- DM the bot → LLM reply
- `/hermes-status` → collector snapshot (open to all)
- `/agent summarise today's findings` → LLM reply (admins only)
- `/channel create release-notes` · `/channel archive <#C…>` · `/channel topic <#C…> text` · `/channel invite <#C…> @user`

## Architecture

| Piece | File |
|------|------|
| Web API client (post/react/admin, `apps.connections.open`) | `app/Support/Slack/SlackApi.php` |
| Socket Mode transport (Pawl WS, acks envelopes) | `app/Support/Slack/SocketModeClient.php` |
| Event router + admin allowlist | `app/Slack/SlackEventRouter.php` |
| LLM bridge (runtime + billing, mirrors ChatController) | `app/Slack/SlackAgentResponder.php` |
| Slash commands | `app/Slack/Commands/*` |
| Daemon (prod-gated) | `app/Console/Commands/SlackListenCommand.php` |

Dependency: `ratchet/pawl` (ReactPHP WebSocket client) over the `react`/`rfc6455`
stack already vendored by Reverb.
