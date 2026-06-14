# Redlines for the v2 .docx masters (`source/`)

> Reviewed 2026-06-12 against branch `runtime-native-l1`. The v2 drafts'
> multi-sector regulatory work is good and is now the master framing.
> Two defect classes remain in ALL FOUR documents: (1) they describe the
> deleted Voiceflow architecture, (2) they claim controls that aren't
> implemented. Apply these replacements verbatim, then re-check every
> claim against `claims-vs-reality.md` before counsel review.

---

## 1. Engine description — replace everywhere

**DELETE** (framework A2 ¶"Conversation engine", short summary ¶1,
medium summary §1, and all equivalents):

> "the Platform is built on Voiceflow as the underlying conversational
> AI engine, accessed exclusively through the Platform's own
> server-side integration."

**REPLACE WITH:**

> "The Platform operates its own conversational runtime. End-user
> conversations are processed by leading large-language-model providers
> — Anthropic (default), OpenAI, and optionally Google — called
> server-side through a single internal contract. The Client selects
> the model tier per assistant; model providers act as sub-processors
> and never interact with end-users directly."

The "engine seam / engine independence" paragraphs may STAY — they are
true and stronger than drafted (the seam was exercised by replacing the
engine entirely).

## 2. Framework A3 "Provisioning" — replace the paragraph

**DELETE** the env-clone description ("asynchronously clones a template
assistant environment… ‘ready' or ‘failed', with retry").

**REPLACE WITH:**

> "On Client onboarding the Platform provisions the assistant
> instantly within its own application — no external engine resources
> are created. The assistant is live immediately; knowledge-base
> content and behavior configuration can be added and published at any
> time thereafter, taking effect on the next conversation."

A3 "Runtime" paragraph: replace "calls the engine… never communicates
with Voiceflow directly" → "calls the selected model provider… never
communicates with any model provider directly; no provider branding or
credential is exposed."

## 3. Framework A4 "Commercial and billing model" — replace

**DELETE** "billed to the Platform at the organisation level — a single
subscription plus a shared, usage-based credit pool…".

**REPLACE WITH:**

> "Model usage is billed to the Platform per token by each provider.
> The Platform operates its own credit ledger for Clients: monthly
> subscription allowances, roll-over top-up packs, per-message metering
> with model-tier multipliers, per-Client limits with suspension on
> exhaustion, and daily reconciliation of the ledger against live
> balances and of token spend against a platform ceiling."

(Every clause in that replacement is implemented and tested.)

## 4. Framework A5 "Information security" — corrections

| Drafted claim | Action |
|---|---|
| "rotated on a scheduled basis" | DELETE until a rotation schedule exists |
| "Personal-data redaction… at the engine layer" (whole bullet) | DELETE — no transcript redaction exists. May claim instead: "Operational logs exclude message content (tested)." |
| "signed session tokens for the chat interface" | REPLACE with "cookie-scoped visitor sessions for the chat interface" |
| "audit logging" | QUALIFY: "an append-only billing ledger, reconciled daily" (no general audit log yet) |
| Sub-processor assurances ¶ (Voiceflow SOC 2/ISO/HIPAA) | REPLACE with the provider table from `claims-vs-reality.md` (Anthropic / OpenAI / Google-paid-tier / Stripe / Pusher / AWS SES) + "[verify each provider's certifications and transfer mechanism before publication]" |

## 5. Framework A7 "Compliance-by-design controls" — re-tense

Implemented today (may stay present-tense): AI-disclosure mechanism ✅
(platform-rendered, tested) · human-oversight escalation path ✅ ·
billing audit ledger ✅ · sub-processor transparency record ✅ (the
claims-vs-reality table).

NOT implemented — change to "planned controls" or delete: per-Client
compliance configuration · consent records · DSR handling workflow ·
general append-only audit log · use-case guardrail enforcement (today
this is contractual via ToS clause 3, not technical) · incident
runbook · data-residency configuration · transfer/TIA register.

## 6. Trust page §5 and §7 — replace

§5 sub-processors: delete the Voiceflow sentence; use:

> "Conversations are processed by leading AI model providers —
> Anthropic, OpenAI[, and Google] — together with Stripe (payments),
> Pusher (realtime), and Amazon Web Services (email). A current
> sub-processor list is available at [link]."

§7 security: delete "Our conversational AI sub-processor maintains
SOC 2 Type II, ISO/IEC 27001:2022, GDPR and HIPAA programmes…" —
replace with provider-neutral text and [verify per provider].

## 7. Sources appendix — swap the Voiceflow block

DELETE the six Voiceflow links. ADD:

- Anthropic — Trust & DPA — https://trust.anthropic.com · https://www.anthropic.com/legal/commercial-terms
- OpenAI — Trust & DPA — https://trust.openai.com · https://openai.com/policies/data-processing-addendum
- Google AI — Gemini API terms (paid tier: no training on data) — https://ai.google.dev/gemini-api/terms
- Stripe — DPA — https://stripe.com/legal/dpa
- Pusher — DPA — https://pusher.com/legal/dpa
- AWS — GDPR Center — https://aws.amazon.com/compliance/gdpr-center/

[Verify each URL and each provider's SCC/DPF status at publication.]

## 8. Already-true claims worth strengthening

- Art. 50 disclosure: may upgrade from "presents this disclosure" to
  "renders the disclosure at the interface layer on every conversation,
  outside Client control, with a one-tap human-handoff request that
  notifies the Client's team immediately (implemented and tested)."
- Engine independence: "demonstrated in production — the original
  engine vendor was replaced without Client-visible change."

## Status note: insurance

The v2 masters reinstate insurance as ONE conditional sector (IDD/DORA
apply only to financial-sector Clients). Per the 2026-06-12 direction
this stands as the multi-sector framing; the earlier insurance-ONLY
drafts remain deleted.
