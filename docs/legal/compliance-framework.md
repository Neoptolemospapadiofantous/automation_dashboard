# Legal & Compliance Framework (draft for counsel)

> **NOT LEGAL ADVICE.** Drafting template. Qualified counsel must
> review, complete `[PLACEHOLDERS]`, and approve before use. Claims
> must match `claims-vs-reality.md` (✅ rows only).

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

### A4. Regulatory position

| Regime | Client | Platform |
|---|---|---|
| GDPR (Reg. 2016/679) | Data controller | Data processor |
| EU AI Act (Reg. 2024/1689) | Deployer | Provider |

**GDPR.** Processor on documented instructions; DPA below; data
minimisation by design (lead capture stores only volunteered contact
fields); erasure supported. *(Formal DSR intake flow: roadmap — see
claims-vs-reality.md before contractually promising response SLAs.)*

**EU AI Act.** Article 50 transparency: the Platform renders an
"AI assistant — not a person" disclosure at the start of every
conversation, at the interface layer, independent of any Client
configuration, with a human-handoff affordance (implemented + tested,
ahead of the 2 Aug 2026 date). The product targets no Annex III
high-risk use cases; Clients warrant their use cases in the ToS.

### A5. Transfers

Model providers process conversation content in the United States.
Mechanism: SCCs + Transfer Impact Assessment [verify per provider
before signature — see the sub-processor table in
claims-vs-reality.md, including the Google paid-tier requirement].

## Part B — Contract skeletons

### Schedule 1 — Data Processing Agreement (GDPR Art. 28)

Clause skeleton, each mapped to Art. 28(3):

1. **Definitions** — GDPR meanings; "Applicable Law" = GDPR [+ Cyprus
   Law 125(I)/2018].
2. **Subject-matter / duration / nature / purpose** — per Annex 1.
3. **Documented instructions** [28(3)(a)] — processing only on the
   Controller's documented instructions, incl. transfers.
4. **Confidentiality** [28(3)(b)] — authorised persons bound.
5. **Security** [28(3)(c)] — Annex 2 = A3 above, implemented items only.
6. **Sub-processors** [28(2),(4)] — general authorisation for Annex 3
   list; change notice with objection right; equivalent obligations
   flowed down; Processor remains liable.
7. **Data-subject rights assistance** [28(3)(e)].
8. **Breach notification & DPIA assistance** [28(3)(f)] — notify
   without undue delay.
9. **Return or deletion at termination** [28(3)(g)].
10. **Audit cooperation** [28(3)(h)].
11. **Transfers** — SCCs + TIA per Annex 3.

Annex 1: processing details · Annex 2: security measures (A3) ·
Annex 3: sub-processor list + transfer mechanisms (claims-vs-reality.md
table). [Counsel to complete all three.]

### Schedule 2 — Terms of Service (skeleton)

- **Service**: branded AI assistant for website Q&A and lead capture.
- **Permitted use**: lawful business assistance; Client warrants no
  EU AI Act high-risk use (Annex III) and no special-category data
  through the assistant [until consent tooling ships].
- **AI transparency**: Platform-rendered disclosure; Client must not
  remove it or present the assistant as human.
- **Fees**: subscription allowance resets each cycle (no rollover);
  purchased top-ups roll over until used; model-tier multipliers per
  the published pricing page; suspension on exhaustion/non-payment
  with notice.
- **Accuracy disclaimer**: AI-generated responses may contain errors
  and are not professional advice.
- **Liability cap** [e.g. 12-month fees]; indirect-loss exclusion;
  statutory carve-outs. [Align to professional-indemnity cover with counsel.]
- **Confidentiality & IP**: Platform retains platform IP; Client
  retains its data and brand, licensing them for service provision.
- **Term / termination / exit**: agent-configuration export; data
  return-or-deletion per DPA.
- **Governing law** [Cyprus]; jurisdiction [Cyprus courts].

### End-user transparency notice (implemented copy)

> "AI assistant — not a person. You can ask for a human at any time."

Rendered in the chat header on every surface; the engine system prompt
additionally forbids claiming to be human.

## Sources

- GDPR — Regulation (EU) 2016/679 — https://eur-lex.europa.eu/eli/reg/2016/679/oj/eng
- EU AI Act — Regulation (EU) 2024/1689 — https://eur-lex.europa.eu/eli/reg/2024/1689/oj/eng
- EU AI Act timeline (EC AI Act Service Desk) — https://ai-act-service-desk.ec.europa.eu
- EU–US Data Privacy Framework participant list — https://www.dataprivacyframework.gov/list
- Cyprus Commissioner for Personal Data Protection — https://www.dataprotection.gov.cy
