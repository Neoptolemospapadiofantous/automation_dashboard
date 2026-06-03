---
title: Completion Events
method: N/A
path: N/A (query flag on /v4/interact/stream)
auth: sessionKey
summary: Streams LLM responses token-by-token via incremental `completion` traces with start/content/end states.
source: https://docs.voiceflow.com/api-reference/conversations-api/completion-events.md
---

# Completion Events

By default the Interact Stream endpoint waits for full LLM generation before emitting text. Enable `completion_events` (or `config.completionEvents: true`) to receive incremental chunks instead.

## Activation

Add `?completion_events=true` to the Interact Stream URL, or set `config.completionEvents: true` in the request body.

## Trace shape

```
type: "completion"
payload:
  state: "start" | "content" | "end"
  content: "<partial text>"   // present only in `content` state
```

## States

| State | Meaning |
|-------|---------|
| `start` | Beginning of a completion stream. |
| `content` | Delivers additional text via `content`. May contain partial words/sentences. |
| `end` | Completion finished. Includes final LLM token usage. |

## Behavior summary

- Without completion events: one `text` trace after generation completes.
- With completion events: multiple `completion` traces with incremental `content` chunks.

## Caller responsibilities

- Stitch `content` chunks together in order.
- Render incrementally on the conversation UI.
- Tolerate incomplete words (UTF-8/word fragments).

## Tips

- For deterministic/canned messages, fake a streaming effect for UI consistency.
- To smooth display, you can group completion traces by sentence delimiters (`.`, `?`, `!`, `;`, `\n`).
