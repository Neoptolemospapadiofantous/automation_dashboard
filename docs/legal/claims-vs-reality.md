# Legal claims vs. implementation reality

> The honesty ledger. A claim may appear in customer-facing legal copy
> ONLY when its row here is ✅. Audit date: 2026-06-12, against branch
> `runtime-native-l1`.

## ✅ True today — safe to claim

| Claim | Evidence |
|---|---|
| Engine isolated behind an internal contract; replaceable without rebuilding | `App\Runtime\Contracts\Runtime` — proven by actually deleting Voiceflow |
| LLM API keys server-side only, never exposed to browsers | Platform-level env keys; no per-customer keys exist at all |
| End-users never reach the model provider directly | All traffic through ChatController/EmbedController proxies |
| Per-customer usage metering + limits + suspension at exhaustion | CreditMeter, per-team buckets, 402 paths — tested |
| Credit ledger reconciles against balances | `credits:reconcile` daily; ledger sum-consistency tested |
| Provider spend monitored against a daily ceiling | `runtime:spend-check` daily |
| Rate limiting on public + abuse-prone endpoints | ThrottleTest pins 5 endpoints + Fortify group throttle |
| **AI disclosure at every conversation, independent of agent scripting** (AI Act Art. 50) | Embed header banner + dashboard chat copy + engine prompt forbids claiming to be human — tested (2026-06-12) |
| Human-escalation path ("ask for a human") | `request_handoff` tool → team-owner email + bell — tested |
| No message bodies in operational logs | LogRedactionTest (sentinel-proofed) |
| Conversations deletable on request (erasure support, basic) | conversations delete endpoint, cascades messages |
| TLS in transit; hashed credentials; CSRF on web forms | Standard Laravel + deploy config; CsrfTest |
| Security scanning: secrets, CVEs (PHP+JS), dependency drift | gitleaks (CI), composer/pnpm audit, audit-sentinel daily |
| Data minimisation in lead capture | capture_lead stores only name/email/phone/company/notes/score |

## 🟡 Partially true — qualify carefully or finish first

| Claim | Gap |
|---|---|
| "Append-only audit log" | True for the credit ledger only; no general action audit log |
| "Supports data-subject rights" | Manual: deletion exists; no formal DSR intake/tracking flow |
| "Webhook verification" | Stripe signature ✓ — that's the only inbound webhook now |
| "Encryption at rest" | Whatever the host DB provides — NOT app-layer. Don't claim until deploy target is chosen |
| "Exit/portability export" | Agent config exports ✓; no full customer-data export |
| "Registration requires Terms/Privacy acceptance" | Jetstream feature ready but OFF (config/jetstream.php) — enable only when counsel approves the policy text; Register.vue checkbox appears automatically |

## ❌ Not true — must NOT appear in any published copy

| Claim (from superseded drafts) | Reality |
|---|---|
| "Built on Voiceflow… SOC 2 Type II, ISO 27001, HIPAA" sub-processor assurances | Voiceflow deleted. Real sub-processors below |
| "PII redaction at the engine layer" | No transcript redaction exists. Transcripts store full message content |
| "Credentials rotated on a scheduled basis" | Env keys; no rotation schedule |
| "Signed session tokens for the chat interface" | Embed identity is a cookie |
| Consent records (versioned, revocable); special-category consent capture | Not implemented |
| Incident/breach runbook with notification timelines | Not written |
| Data-residency configuration per customer | Single deployment |
| WCAG 2.1 AA conformance | Never assessed |
| Template-environment cloning / provisioning pipeline | Two architectures ago; provisioning is instant + internal |

## Actual sub-processor list (for Annex 3 / trust page)

| Sub-processor | Purpose | Data touched | Notes |
|---|---|---|---|
| Anthropic (US) | LLM — Claude tiers | conversation content | DPA available; verify SCC/DPF status before publishing |
| OpenAI (US) | LLM (ChatGPT tier) + embeddings | conversation content, KB text | same |
| Google (US) | LLM (Gemini tier, optional) | conversation content | **paid tier only** — free tier trains on data |
| Stripe (US/IE) | payments | billing identity, no conversation data | |
| Pusher (UK/US) | realtime UI events | lead names/snippets in broadcast payloads | EU cluster available |
| AWS SES | transactional email | email addresses, notification content | |
| Typesense (optional) | search | message text if enabled | currently OFF (DB fallback) |

Each row needs a verified transfer mechanism (SCCs vs DPF) + TIA before
the trust page publishes — per the drafts' own checklist.
