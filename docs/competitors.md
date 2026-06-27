---
type: reference
status: current
tags: [business, strategy, competitors, positioning]
date: 2026-06-27
---

# Competitors & where we win

A map of the field Flowstack plays in (mid-2026) and the wedge we drive
through it. Companion docs: [[business-model]] (what we sell + pricing),
[[phase-16-automations]] (the automation feature that moves us up a category).

> TL;DR — Two markets are converging: **"answer questions on my site"**
> (Chatbase et al.) and **"run my business operations"** (GoHighLevel et al.).
> The first is commoditizing fast. The second is sticky but bloated and
> non-technical. Flowstack's bet: **an AI agent that can both *talk* and
> *do*** — chat as the brain, n8n as the hands — sold to operators who want
> automation without becoming an automation consultancy.

---

## 1. The two categories

| | **Chatbot category** | **Operations/automation category** |
|---|---|---|
| Promise | "Answer visitor questions from my docs" | "Run my marketing/sales/ops" |
| Players | Chatbase, SiteGPT, CustomGPT, Tidio/Lyro, Crisp | GoHighLevel, HubSpot, Intercom |
| Pricing | $30–500/mo, per-message or per-seat | $97–500+/mo, per-seat/per-location |
| Moat | thin — RAG-over-docs is a weekend project now | thick — switching cost, data lock-in |
| Weakness | can only *talk*; no hands | bloated, non-technical, no real LLM agent |

The chatbot category is **commoditizing**: retrieval-augmented Q&A over a
website is no longer a defensible product. The operations category is **sticky
but old**: it owns the customer's data and workflows, but its "AI" is bolt-on
and its automation is rigid, point-and-click, single-vendor.

Flowstack sits deliberately **between** them — and the bridge is the
[automation tool-call](./phase-16-automations.md): the agent decides mid-turn
to call an operator-configured n8n workflow (look up an order, open a ticket,
sync a CRM) and weaves the result back into the reply.

---

## 2. The field

### Pure chatbot / RAG

**Chatbase** — the category leader. $40 (Hobby, 500 credits) → $150 (Standard,
10k) → $500 (Pro, 15k) /mo, message-credit metered (1–5 credits/response by
model). Polished, fast to set up, huge template library. It now ships
**"AI Actions"** — but capped (5/agent on Hobby, 12/agent on Pro) and bound to
its own builder. *Weakness:* the actions are a walled, quota'd add-on, not a
general workflow engine; the moment a customer needs real multi-step
orchestration they hit the ceiling. *Where we win:* we don't cap actions or
make you learn a bespoke builder — the agent reaches into n8n's 400+
integrations directly.

**SiteGPT** — $39 (Starter, 4k msgs) → $79 (Growth) → $259 (Scale, 40k msgs)
/mo; Enterprise custom. Similar shape, stronger on multi-language and
human-handoff. *Weakness:* same ceiling — it's a smarter FAQ. *Where we win:*
same wedge; plus our per-turn credit model is more honest than message-tier
overage math for low/medium-volume operators.

**CustomGPT.ai** — $29 (Starter) → $99 (Standard) → $449–499 (Premium) /mo,
document-heavy (great PDF/sitemap
ingestion), API-first. *Weakness:* developer-flavored, no opinion about the
business operating on top of it. *Where we win:* we're not selling a RAG API,
we're selling an operator a *running assistant* with billing, handoff, and
automations wired in — less assembly required.

### Chat + light automation

**Tidio + Lyro** — SMB live-chat incumbent; Lyro is the AI layer. Tidio base
plans ~$29 → $749/mo; Lyro is a separate add-on from **$39/mo for 50 AI
conversations** (~$0.50–0.65 each). Strong e-commerce presence. *Weakness:*
automation is template-bound (Tidio "flows"), not a general engine; the AI and
the automation are separate products bolted together. *Where we win:* one
agent that reasons *and* reaches into a general-purpose workflow engine, not a
flowchart builder.

**Crisp** — team-inbox + chat, flat workspace pricing: Free → Mini $45 →
Essentials $95 → **Plus $295**/mo. Loved for the inbox UX. *Weakness:* the AI
(MagicReply/MagicType) is **gated behind the $295 top tier**, and even there
it's assistive — it drafts replies for humans, it isn't an autonomous agent;
automation is shallow. *Where we win:* we ship an agent that closes the loop
itself, and our automation is a real engine, not reply suggestions.

### Enterprise support AI

**Intercom Fin** — the high end. **$0.99 per resolution** (50-resolution/mo
minimum; also $0.99 per handoff/disqualification, $9.99 per qualification),
optionally on top of Intercom seats from $29/mo. Genuinely good resolution
rates, deep product. *Weakness:* expensive and aimed at funded support orgs;
per-outcome pricing punishes success and is hostile to SMBs; automation lives
inside Intercom's walled garden.
*Where we win:* SMB/operator segment Intercom doesn't court, transparent
credit pricing, and **open** automation via n8n instead of Intercom-only
workflows.

### Agent builders (the closest category comp)

**Voiceflow** — the nearest competitor to our exact thesis, and notably
Flowstack's *former* engine (removed in the native-runtime rebuild — see
[[phase-15-voiceflow-wrapper]], [[runtime-native]]). It pivoted in 2026 from a
scripted conversation-flow builder into an **"AI agent platform"**: its
*Agent Step* lets agents reason and act autonomously instead of following
pre-scripted paths, with **300+ native integrations + ~2,800 via Pipedream**.
Pricing stacks: Pro $60/mo + Business $150/mo, **plus $50/editor seat plus
usage credits** — a 5-editor support team lands ~$450–500/mo, and agents
*hard-stop* when monthly credits run out. *Weakness:* it sells a **builder
canvas** you assemble, not a finished operator product; seat + credit stacking
is expensive; automation rides Pipedream. *Where we win:* we ship a *running
product* (multitenancy, billing, handoff, lead capture, branded widget, audit)
— configure, don't build; seat-free usage pricing; self-hostable n8n as the
automation layer. We don't out-platform Voiceflow — we win the operator who
wants the *outcome*, not the *toolkit*.

### All-in-one operations (the category we're climbing into)

**GoHighLevel** — the real strategic comp. ~$97 (Starter) → $297 (Unlimited)
→ $497 (Pro/SaaS-mode) /mo. CRM + funnels + email/SMS + booking + a workflow
builder, sold hard to agencies who **resell it white-label**. *Strength:* owns
the customer's data and operations — enormous switching cost. *Weakness:* the
"AI" is a recent, shallow add-on; the automation builder is proprietary,
rigid, and notoriously fiddly; the whole suite is overwhelming for a single
operator who just wants their site to *work*. *Where we win:* we don't try to
out-feature GHL. We win the **AI-agent-first** entry point — the operator
starts with a great assistant, and automation grows *out of conversations*
(the agent calls workflows the operator authors), instead of starting with a
800-feature CRM they have to assemble. n8n gives us GHL-class automation
breadth without building it ourselves.

---

## 3. Where Flowstack wins — the wedge

1. **Talk *and* do, in one agent.** The chatbot pack can't act; the ops pack
   can't reason. We're the agent that decides, mid-conversation, to fire a
   real workflow and use the result. This is the [#16 automation
   tool-call](./phase-16-automations.md) — the single feature that moves us
   from "a chatbot" to "an AI agent + automation platform."

2. **n8n as the hands = unbounded automation without building it.** Every
   competitor either has no automation or a proprietary, limited builder. By
   standing on n8n we inherit 400+ integrations and a real workflow engine on
   day one — and operators who already know n8n feel at home.

3. **Honest, usage-based credit pricing.** Not per-seat (GHL/Crisp), not
   per-resolution-that-punishes-success (Fin), not opaque message tiers. One
   credit ledger, multiplier-weighted per turn, top-ups roll over. Easy to
   reason about for a small operator.

4. **Operator-grade plumbing already in the box.** Multitenancy, draft→publish
   versioned agent config, human handoff, lead capture, audit trails, branded
   widget/embed. A customer isn't assembling primitives — they're configuring
   a product.

5. **The data layer is the long moat.** Every automation run and conversation
   turn is audited (`automation_runs`, transcript storage). That structured
   record of *what the business actually does* is the seed of the #14 data
   layer — the thing we eventually sell back to operators (analytics,
   suggested automations, optimization) and the thing competitors with
   bolt-on AI can't easily reconstruct.

## 4. Where we're exposed (be honest)

- **RAG is commoditized** — "answer from my docs" alone is not a business.
  Our defensibility has to live in *act + data*, not retrieval.
- **GHL's switching cost is real** — once a business runs its CRM/funnels on
  GHL, we're not displacing the whole suite. We win *new* operators and the
  AI-first entrants, not rip-and-replace.
- **n8n dependency is double-edged** — it's our automation breadth but also a
  moving part we don't fully own; the operator-facing Actions UI has to hide
  that complexity (see [[phase-16-automations]] remaining work).
- **Distribution** — the chatbot incumbents have template galleries and SEO;
  we need an acquisition motion (see the launch/onboarding work), not just a
  better product.

## 5. One-line positioning

> **Chatbase answers. GoHighLevel runs your ops and overwhelms you. Flowstack
> is the AI agent that talks to your customers and *does the work* — without
> making you an automation consultant.**
