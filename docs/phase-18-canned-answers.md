# Phase 18 — Canned answers (deterministic FAQ shortcut)

**The cheapest possible turn.** For a landing page, a handful of questions —
pricing, features, how it works — are most of the traffic. Answering them with
the LLM costs tokens (and a credit) every time. This phase serves those answers
from operator-authored config **without calling the model at all**: zero tokens,
zero credits.

> Status: **shipped.** Runtime shortcut (`app/Runtime/Canned`), embed wiring
> (chips + zero-bill interact), and the operator FAQ editor all landed.

## How it works

```
visitor message (typed, or a tapped chip)
  → EmbedController::interact
      • CannedAnswers::forAgent($id)->match($message)   ← BEFORE the credit pre-check
      • hit  → record user + answer, broadcast, return {canned: true}  (no LLM, no debit)
      • miss → fall through to the normal runtime turn (billed as usual)
```

Matching is deterministic (no embedding call, so the shortcut itself is free):

- A **chip tap** sends the category label verbatim → exact category match.
- A **typed question** matches if it contains any of the category's keywords
  (lowercased substring). First defined category wins.

The check runs **before** the credit pre-check, so canned answers keep working
even when the team is out of credits — an FAQ never costs anything and never goes
dark.

## Where things live

| Piece | Path |
|---|---|
| Value object (one shortcut) | `app/Runtime/Canned/CannedAnswer.php` |
| Catalog (chips + match) | `app/Runtime/Canned/CannedAnswers.php` |
| Runtime wiring + chips in launch | `app/Http/Controllers/EmbedController.php` |
| Widget chip rendering | `resources/views/embed/chat.blade.php` (`addQuickReplies`) |
| Operator FAQ editor | `app/Http/Controllers/AgentFaqController.php`, `resources/js/Pages/Agents/Faq.vue` |

## Storage & lifecycle

Canned answers live in the agent's config under `canned_answers`, the SAME draft
the behavior + Actions editors write. They inherit draft → publish → rollback for
free, and drafts are invisible to the runtime (only PUBLISHED answers match) —
identical to how automations work. Each row is
`{category, keywords: [...], answer}`.

The FAQ editor is gated to the Hermes operator tier like Actions: canned answers
are part of the managed-service setup, not a client-facing knob.

## Widget chips

`launch` returns the published category labels as `chips`; the widget renders
them as quick-reply buttons alongside any configured starter prompts. Tapping one
sends its label, which the exact-category match answers for free.

## Relationship to the knowledge base

Canned answers and the RAG knowledge base are complementary: canned answers are
for the *known, high-frequency* questions (fixed wording, zero cost); the
knowledge base grounds *everything else* the LLM handles. A message that matches
no category falls through to the normal grounded turn.
