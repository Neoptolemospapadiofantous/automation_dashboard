# Legal & Compliance Framework — generic vertical (draft for counsel)

> **NOT LEGAL ADVICE.** Drafting template adapted 2026-06-12 from the
> insurance-vertical framework (`insurance-vertical/`) to the generic
> SMB product and the native runtime. Qualified counsel must review,
> complete `[PLACEHOLDERS]`, and approve before use. Claims must match
> `claims-vs-reality.md` (✅ rows only).

## Part A — Framework

### A1. The Platform

[Company Name] Ltd operates an AI chat-assistant platform for business
websites: visitor Q&A grounded in the Client's own knowledge base, and
lead capture. Each Client receives a private, branded assistant,
provisioned instantly within the Platform — **no third-party
conversational engine is involved**.

**Engine.** The Platform operates its own conversational runtime (a
Laravel application) that calls large-language-model APIs — Anthropic
(default), OpenAI, and optionally Google — through a single internal
contract. The Client chooses the model tier per assistant; model
providers are sub-processors (Annex 3) and never interact with
end-users directly. All model credentials are platform-side only.

**Knowledge grounding.** Assistant answers are grounded in
Client-approved content (uploaded documents / pages). The system prompt
instructs the assistant never to invent facts, prices, or policies.

### A2. Commercial model

Clients purchase a subscription (monthly credit allowance) and optional
top-up credit packs (which roll over until used). The Platform meters
every message, enforces per-Client limits, suspends on exhaustion, and
maintains an append-only credit ledger reconciled daily against live
balances. Provider usage is metered per-model-tier and monitored
against a daily spend ceiling.

### A3. Information security (implemented measures only)

- TLS in transit; provider API credentials server-side only, never in
  browsers, logs, or source control (CI secret-scanning on every push).
- Team-scoped authorisation on every resource; cross-tenant access
  prevented and tested.
- Rate limiting on all public and abuse-prone endpoints, including
  registration and password flows.
- Operational logs exclude message bodies (tested redaction).
- Daily automated security scans (secrets, dependency CVEs PHP+JS),
  6-hourly system health checks, dependency review weekly.
- [Encryption at rest / backups: state per chosen hosting — see
  claims-vs-reality.md before claiming.]

### A4. Regulatory position (generic vertical)

| Regime | Client | Platform |
|---|---|---|
| GDPR | Data controller | Data processor |
| EU AI Act | Deployer | Provider |

**GDPR.** Processor on documented instructions; DPA below; data
minimisation by design (lead capture stores only volunteered contact
fields); erasure supported. *(Formal DSR intake flow: roadmap — see
claims-vs-reality.md before contractually promising response SLAs.)*

**EU AI Act (Reg. 2024/1689).** Article 50 transparency: the Platform
renders an "AI assistant — not a person" disclosure at the start of
every conversation, at the interface layer, independent of any Client
configuration, with a human-handoff affordance (implemented + tested,
ahead of the 2 Aug 2026 date). The generic product targets no Annex III
high-risk use cases; Clients warrant their use cases in the ToS.

**Not engaged at this vertical:** DORA, IDD (no financial-entity
clients targeted). The parked insurance-vertical drafts cover both for
when that market activates.

### A5. Transfers

Model providers process conversation content in the United States.
Mechanism: SCCs + Transfer Impact Assessment [verify per provider
before signature — see the sub-processor table in
claims-vs-reality.md, including the Google paid-tier requirement].

## Part B — Contract skeletons

### Schedule 1 — DPA (GDPR Art. 28)

Substantively identical to the insurance draft's Schedule 1 (it is
vertical-neutral): documented instructions · confidentiality · Art. 32
measures (Annex 2 = A3 above, implemented items only) · sub-processor
authorisation + change notice (Annex 3 = the real provider list) ·
DSR assistance · breach notification without undue delay · return or
deletion at termination · audit cooperation · SCCs for transfers.
[Counsel to complete Annexes 1–3.]

### Schedule 2 — Terms of Service (deltas from the insurance draft)

- Permitted use: lawful business website assistance and lead capture;
  Client warrants no EU AI Act high-risk use (Annex III) and no
  processing of special-category data through the assistant
  [until consent tooling ships].
- AI transparency: Platform-rendered disclosure; Client must not
  remove it or present the assistant as human.
- Fees: subscription allowance resets each cycle (no rollover);
  purchased top-ups roll over until used; model-tier multipliers per
  the published pricing page.
- Accuracy disclaimer; liability cap [12-month fees]; confidentiality;
  IP; term/termination; export of agent configuration on exit;
  governing law [Cyprus] — all as per the insurance draft, minus
  Schedules 3 (DORA) and 4's insurance-specific scope.

### End-user transparency notice (implemented copy)

> "AI assistant — not a person. You can ask for a human at any time."

Rendered in the chat header on every surface; the engine system prompt
additionally forbids claiming to be human.

## Sources

Inherit the EUR-Lex source list from
`insurance-vertical/Legal-Compliance-Contractual-Framework.docx`
(GDPR, AI Act, EAA, NIS2 links remain valid; DORA/IDD parked).
