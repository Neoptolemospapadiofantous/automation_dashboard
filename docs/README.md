# Dashboard documentation

Living architecture + design notes for the multi-tenant SaaS dashboard
for AI lead-qualification agents, powered by the native Flowstack
runtime (the Voiceflow integration was fully removed — phase docs
below are historical records). Each file documents
one cohesive piece of work; phase docs are written when shipped and kept
as historical record (don't rewrite history when the design evolves —
write a new phase doc that supersedes the prior one).

## Reading order for newcomers

1. [phase-1-foundation.md](./phase-1-foundation.md) — [[phase-1-foundation|Laravel + Jetstream + Inertia base]]
2. [phase-13-multitenancy.md](./phase-13-multitenancy.md) — [[phase-13-multitenancy|current tenancy model + lifecycle]] (read this if you're touching anything customer-facing)
3. [public-surface.md](./public-surface.md) — the public API contract consumed by the marketing site
4. The phase doc for whatever you're working on

## Index by topic

### Tenancy + lifecycle
- [phase-13-multitenancy.md](./phase-13-multitenancy.md) — per-team agents, state machine, project-pool provisioning, onboarding wizard (BYOK was removed from the product surface in Phase 14; the `mode` column remains for ops-only Custom-tier use)

### Voiceflow integration
- [phase-5-voiceflow.md](./phase-5-voiceflow.md) — [[phase-5-voiceflow|Dialog Manager proxy, lead capture, chat panel]]
- [phase-6-conversation-storage.md](./phase-6-conversation-storage.md) — [[phase-6-conversation-storage|conversations + messages persistence]]
- [phase-11-transcript-backfill.md](./phase-11-transcript-backfill.md) — [[phase-11-transcript-backfill|Voiceflow Transcripts API import]]
- [phase-12-knowledge-base.md](./phase-12-knowledge-base.md) — Voiceflow KB API
- [legal/README.md](./legal/README.md) — legal & compliance drafts: trust page + framework (GDPR + AI Act), claims-vs-reality honesty ledger
- [../CONTRIBUTING.md](../CONTRIBUTING.md) — workflow conventions, [IF-*] assurance decisions, deferred items
- [../PROJECT_ASSURANCE_STRATEGY.md](../PROJECT_ASSURANCE_STRATEGY.md) — the assurance spec (categories A–I); suites live in tests/{Wiring,Integrity,Security,Contracts,Snapshots,Performance}
- [operations/pricing-audit.md](./operations/pricing-audit.md) — provider rates verified 2026-06-11, credit economics, margin matrix, standing invariants
- [agent-lifecycle.md](./agent-lifecycle.md) — **operator guide**: the full signup → teach → publish → install → leads journey (mirrored by the dashboard setup checklist)
- [runtime-native.md](./runtime-native.md) — **the current engine**: Flowstack-owned LLM runtime (Anthropic + RAG), the only engine; Voiceflow was removed
- [phase-15-voiceflow-wrapper.md](./phase-15-voiceflow-wrapper.md) — full wrapper (typed subclients, evaluations, environments, session + org webhooks); supersedes Phase 5's ad-hoc client
- [voiceflow/README.md](./voiceflow/README.md) — Voiceflow API reference dump (vendor docs, frozen)

### Pipeline + UX
- [phase-3-leads.md](./phase-3-leads.md) — [[phase-3-leads|kanban board, live updates]]
- [phase-7-delegation.md](./phase-7-delegation.md) — [[phase-7-delegation|round-robin + manual lead assignment]]

### Public surface
- [public-surface.md](./public-surface.md) — `/api/public/stats` contract, safety doctrine, bucketing
- [phase-14-public-stats.md](./phase-14-public-stats.md) — phase doc: how the public surface was built
- [architecture/public-stats-flow.md](./architecture/public-stats-flow.md) — dashboard data flow (Mermaid)
- [architecture/landing-sse-pipeline.md](./architecture/landing-sse-pipeline.md) — landing-side SSE pipeline (Mermaid)

### Design
- [theme-unification.md](./theme-unification.md) — landing↔dashboard↔embed shared tokens ("two sheets, one ink"). Phase 1 built; Phases 2–3 planned

### Infra + ops
- [phase-2-realtime.md](./phase-2-realtime.md) — [[phase-2-realtime|broadcasting backbone (Pusher / Echo)]]
- [phase-4-deploy.md](./phase-4-deploy.md) — Forge deploy command
- [typesense-setup.md](./typesense-setup.md) — conversation search backend

### Hermes — CI + agent audits
- [hermes/README.md](./hermes/README.md) — [[hermes/README|operational doc, commands, billing context]]
- [hermes/index.md](./hermes/index.md) — [[hermes/index|chronological index of agent session notes]]

## Conventions

- **Phase docs are immutable history.** Don't rewrite. If a design changes, add a new section noting the supersedure and link forward (see Phase J → Phase K in phase-13).
- **Code is the source of truth.** Doc files explain *why* and *how it fits together*. Don't duplicate code — link to it with file paths.
- **Diagrams in Mermaid**, fenced as ` ```mermaid `. GitHub renders them inline.
- **Public API contracts get their own file** (not buried in a phase doc) because external consumers depend on them and grep is faster than scrolling.
