# Phase 17 — Agent collaboration & operator CLI surfaces

Until now every agent conversation had a human on one end: the web Chat
page, the embed widget, or the Slack bot. This phase adds the primitive
that turns **one agent's output into another agent's input** — and two
operator CLI surfaces on the same native runtime, so a Flowstack operator
can drive agents (and rooms of agents) from the terminal.

> Status: **shipped** (`2857e5a`). Operator-only — nothing here is
> reachable from the web; all three entry points are artisan commands.

## Where things live

| Piece | Path |
|---|---|
| Conversation primitive | `app/Runtime/Collab/AgentConversation.php` |
| Room ledger | `app/Runtime/Collab/RoomLedger.php` |
| Round-table CLI | `app/Console/Commands/AgentsCollab.php` (`agents:collab`) |
| Terminal chat CLI | `app/Console/Commands/AgentsTerminal.php` (`agents:terminal`) |
| Project-docs ingest CLI | `app/Console/Commands/AgentsIngestProject.php` (`agents:ingest-project`) |
| Tests | `tests/Feature/Collab/AgentConversationTest.php`, `tests/Feature/Commands/AgentsCommandsTest.php` |

## Design

### `AgentConversation` — relay & round-table

`relay(agent, message, room, bill)` delivers one message to one agent
inside a **room** and returns its reply lines. Three invariants:

- **Memory** — each agent keeps its own runtime session keyed by the room
  (`visitor = "room:<room>"`), so it remembers the exchange across turns
  and across CLI invocations. Same session model as web chat; nothing new.
- **Shared record** — every turn (including skipped ones) is appended to
  the `RoomLedger`, so a collaboration is auditable and resumable.
- **Consistent books** — each turn bills the *speaking agent's* team via
  `CreditMeter` with the same formula every other surface uses:
  `(1 + replies) × AgentConfigVersion::creditsPerMessage(agent)`. An
  out-of-credits team is pre-checked *before* the turn (skipped turn goes
  to the ledger with `error: out_of_credits`); a debit that races past
  zero after the reply is tolerated — the next turn's pre-check blocks.

`roundtable(agents, topic, rounds, room, bill)` chains relays: the first
agent answers the topic, every later turn receives the previous speaker's
reply ("A teammate said: …"). Agents may span teams — each turn bills its
own speaker's team.

### `RoomLedger` — append-only room record

One JSON file per room at `data/agents/rooms/<slug>.json` — the same
`data/agents/*` layout the scheduled Hermes collectors use; `data/` is
gitignored (local state, not repo content). Room names are slugged
(`Str::slug`) before touching the filesystem, so a hostile room id can't
path-traverse. `reset(room)` deletes the ledger file.

## The CLI surfaces

| Command | Signature highlights | Does |
|---|---|---|
| `agents:collab` | `--agents=<id,slug,name,…>` `--topic=` `--rounds=1` `--room=` `--reset` `--no-bill` | Round-table of 2+ agents on a topic; prints the transcript and the ledger path. |
| `agents:terminal` | `--team=` `--agent=` `--visitor=terminal-cli` `--message=` `--no-bill` | Interactive chat with one agent on the native runtime. Resumes the session for the same `--visitor`; `/reset`, `/transcript`, `/exit` in-loop; `--message` for a one-shot scripted turn. Bills exactly like `ChatController`. |
| `agents:ingest-project` | `{path}` `--team=2` `--name=` `--reset` | Creates (or reuses) a per-project agent and ingests the project's markdown (`README`, `AGENTS.md`, `CLAUDE.md`, `docs/**`) into its knowledge base via `KnowledgeStore`. Bounded: ≤ 60 files, ≤ 200 KB each, depth < 4, `node_modules`/`vendor`/`.git`/build trees skipped; paths resolved via `realpath`. |

`--no-bill` exists on the chat-driving commands for local development
only — production operator sessions bill normally so CLI usage never
drifts the books.

## What this is *not*

- Not a client feature: no web routes, no UI. Console-only, which is why
  the authorization story is "whoever can run artisan" (i.e. operators).
- Not new runtime infrastructure: rooms reuse the existing
  `Runtime::launch/sendText/transcript` session machinery; collab adds
  only the relay loop and the ledger.

## Related

- [runtime-native.md](./runtime-native.md) — the runtime session model rooms build on.
- [operations/commands.md](./operations/commands.md) — the full artisan command reference (these three included).
- [business-model.md](./business-model.md) — the credit formula the relay bills with.
