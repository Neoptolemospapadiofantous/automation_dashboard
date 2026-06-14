# Dashboard documentation

Living architecture + design notes for the multi-tenant SaaS dashboard
for AI lead-qualification agents, powered by the native Flowstack
runtime (the legacy third-party engine was fully removed — its phase
docs below are historical records). Each file documents
one cohesive piece of work; phase docs are written when shipped and kept
as historical record (don't rewrite history when the design evolves —
write a new phase doc that supersedes the prior one).

## Reading order for newcomers

0. [project-overview.md](./project-overview.md) — **start here**: the whole system in one page (runtime, billing, public surface, compliance, brand, ops, current state)
1. [phase-1-foundation.md](./phase-1-foundation.md) — [[phase-1-foundation|Laravel + Jetstream + Inertia base]]
2. [phase-13-multitenancy.md](./phase-13-multitenancy.md) — [[phase-13-multitenancy|current tenancy model + lifecycle]] (read this if you're touching anything customer-facing)
3. [public-surface.md](./public-surface.md) — the public API contract consumed by the marketing site
4. The phase doc for whatever you're working on

## Index by topic

### Tenancy + lifecycle
- [phase-13-multitenancy.md](./phase-13-multitenancy.md) — per-team agents, state machine, project-pool provisioning, onboarding wizard (BYOK was removed from the product surface in Phase 14; the `mode` column remains for ops-only Custom-tier use)
- [authorization.md](./authorization.md) — roles, `TeamPolicy`, the `AuthorizesByTeamRole` trait — how team actions are gated (`app/Authorization`, `app/Policies`)
- [architecture/state-machines.md](./architecture/state-machines.md) — the lifecycle state-machine pattern (Agent/Lead/Conversation transitions, `HasLifecycle`) (`app/Lifecycle`)
- [architecture/data-model.md](./architecture/data-model.md) — the consolidated schema / ERD: entities, relationships, the `team_id`/`agent_id` tenancy spine (`app/Models`)

### Business & domain
- [business-model.md](./business-model.md) — the commercial model: plans, the 5 tiers, two-bucket credits, top-up packs, margins, how money flows through Stripe
- [glossary.md](./glossary.md) — domain vocabulary (Team / Agent / Lead / Visitor / Credit / Tier / Flow / Tool …), each grounded in code

### Security
- [security.md](./security.md) — engineering security posture: surface boundaries, headers/framing, tenant isolation, rate limits, secrets, the `tests/Security` guarantees, honest gaps
- [authorization.md](./authorization.md) — the role/permission model (also linked under Tenancy)

### Conversational engine (history)
- [phase-5-voiceflow.md](./phase-5-voiceflow.md) — [[phase-5-voiceflow|legacy-engine proxy, lead capture, chat panel]] (historical — superseded by the native runtime)
- [phase-6-conversation-storage.md](./phase-6-conversation-storage.md) — [[phase-6-conversation-storage|conversations + messages persistence]]
- [phase-11-transcript-backfill.md](./phase-11-transcript-backfill.md) — [[phase-11-transcript-backfill|legacy-engine transcript import]]
- [phase-12-knowledge-base.md](./phase-12-knowledge-base.md) — legacy-engine KB API
- [legal/README.md](./legal/README.md) — legal & compliance drafts: trust page + framework (GDPR + AI Act), claims-vs-reality honesty ledger
- [../CONTRIBUTING.md](../CONTRIBUTING.md) — workflow conventions, [IF-*] assurance decisions, deferred items
- [../PROJECT_ASSURANCE_STRATEGY.md](../PROJECT_ASSURANCE_STRATEGY.md) — the assurance spec (categories A–I); suites live in tests/{Wiring,Integrity,Security,Contracts,Snapshots,Performance}
- [operations/pricing-audit.md](./operations/pricing-audit.md) — provider rates verified 2026-06-11, credit economics, margin matrix, standing invariants
- [agent-lifecycle.md](./agent-lifecycle.md) — **operator guide**: the full signup → teach → publish → install → leads journey (mirrored by the dashboard setup checklist)
- [runtime-native.md](./runtime-native.md) — **the current engine**: Flowstack-owned LLM runtime (Anthropic + RAG), the only engine; the legacy engine was removed
- [phase-15-voiceflow-wrapper.md](./phase-15-voiceflow-wrapper.md) — full legacy-engine wrapper (typed subclients, evaluations, environments, session + org webhooks); historical, superseded by the native runtime
- [voiceflow/README.md](./voiceflow/README.md) — archived legacy-engine API reference dump (vendor docs, frozen — kept for historical reference)

### Pipeline + UX
- [phase-3-leads.md](./phase-3-leads.md) — [[phase-3-leads|kanban board, live updates]]
- [phase-7-delegation.md](./phase-7-delegation.md) — [[phase-7-delegation|round-robin + manual lead assignment]]

### Public surface
- [public-surface.md](./public-surface.md) — `/api/public/stats` contract, safety doctrine, bucketing
- [phase-14-public-stats.md](./phase-14-public-stats.md) — phase doc: how the public surface was built
- [architecture/public-stats-flow.md](./architecture/public-stats-flow.md) — dashboard data flow (Mermaid)
- [architecture/landing-sse-pipeline.md](./architecture/landing-sse-pipeline.md) — landing-side SSE pipeline (Mermaid)

### Design
- [design-system.md](./design-system.md) — **the brand reference + rules**: tokens, two sheets, component registers, the `text-ink-mute` contrast rule, semantic-color exception
- [theme-unification.md](./theme-unification.md) — landing↔dashboard↔embed shared tokens ("two sheets, one ink"). Phases 1–2 built; Phase 3 (Tailwind v4) planned

### Infra + ops
- [phase-2-realtime.md](./phase-2-realtime.md) — [[phase-2-realtime|broadcasting backbone (Pusher / Echo)]]
- [domain-events.md](./domain-events.md) — the domain-event seam (`app/Events/Domain` — dispatched on every lifecycle transition, intentionally unlistened)
- [operations/commands.md](./operations/commands.md) — artisan commands + the cron schedule (`app/Console/Commands`, `routes/console.php`)
- [phase-4-deploy.md](./phase-4-deploy.md) — Forge deploy command
- [typesense-setup.md](./typesense-setup.md) — conversation search backend

### Hermes — CI + agent audits
- [hermes/README.md](./hermes/README.md) — [[hermes/README|operational doc, commands, billing context]] — the tree architecture (manifest trunk → checks → enrich → synthesize → split)
- [hermes/effectiveness.md](./hermes/effectiveness.md) — **is it working?** generated quality-KPI trend + regression check (`composer hermes-metrics`)
- [hermes/index.md](./hermes/index.md) — [[hermes/index|chronological index of agent session notes]]

## Conventions

- **Phase docs are immutable history.** Don't rewrite. If a design changes, add a new section noting the supersedure and link forward (see Phase J → Phase K in phase-13).
- **Code is the source of truth.** Doc files explain *why* and *how it fits together*. Don't duplicate code — link to it with file paths.
- **Diagrams in Mermaid**, fenced as ` ```mermaid `. GitHub renders them inline.
- **Public API contracts get their own file** (not buried in a phase doc) because external consumers depend on them and grep is faster than scrolling.
