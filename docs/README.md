# Dashboard documentation

Living architecture + design notes for the multi-tenant SaaS dashboard
that wraps Voiceflow agents for lead qualification. Each file documents
one cohesive piece of work; phase docs are written when shipped and kept
as historical record (don't rewrite history when the design evolves —
write a new phase doc that supersedes the prior one).

## Reading order for newcomers

1. [phase-1-foundation.md](./phase-1-foundation.md) — Laravel + Jetstream + Inertia base
2. [phase-13-multitenancy.md](./phase-13-multitenancy.md) — current tenancy model + lifecycle (read this if you're touching anything customer-facing)
3. [public-surface.md](./public-surface.md) — the public API contract consumed by the marketing site
4. The phase doc for whatever you're working on

## Index by topic

### Tenancy + lifecycle
- [phase-13-multitenancy.md](./phase-13-multitenancy.md) — per-team agents, modes (BYOK vs managed), state machine, project-pool provisioning, onboarding wizard

### Voiceflow integration
- [phase-5-voiceflow.md](./phase-5-voiceflow.md) — Dialog Manager proxy, lead capture, chat panel
- [phase-6-conversation-storage.md](./phase-6-conversation-storage.md) — conversations + messages persistence
- [phase-11-transcript-backfill.md](./phase-11-transcript-backfill.md) — Voiceflow Transcripts API import
- [phase-12-knowledge-base.md](./phase-12-knowledge-base.md) — Voiceflow KB API
- [voiceflow/README.md](./voiceflow/README.md) — Voiceflow API reference dump (vendor docs, frozen)

### Pipeline + UX
- [phase-3-leads.md](./phase-3-leads.md) — kanban board, live updates
- [phase-7-delegation.md](./phase-7-delegation.md) — round-robin + manual lead assignment

### Public surface
- [public-surface.md](./public-surface.md) — `/api/public/stats` contract, safety doctrine, bucketing
- [phase-14-public-stats.md](./phase-14-public-stats.md) — phase doc: how the public surface was built
- [architecture/public-stats-flow.md](./architecture/public-stats-flow.md) — dashboard data flow (Mermaid)
- [architecture/landing-sse-pipeline.md](./architecture/landing-sse-pipeline.md) — landing-side SSE pipeline (Mermaid)

### Infra + ops
- [phase-2-realtime.md](./phase-2-realtime.md) — broadcasting backbone (Pusher / Echo)
- [phase-4-deploy.md](./phase-4-deploy.md) — Forge deploy command
- [typesense-setup.md](./typesense-setup.md) — conversation search backend

## Conventions

- **Phase docs are immutable history.** Don't rewrite. If a design changes, add a new section noting the supersedure and link forward (see Phase J → Phase K in phase-13).
- **Code is the source of truth.** Doc files explain *why* and *how it fits together*. Don't duplicate code — link to it with file paths.
- **Diagrams in Mermaid**, fenced as ` ```mermaid `. GitHub renders them inline.
- **Public API contracts get their own file** (not buried in a phase doc) because external consumers depend on them and grep is faster than scrolling.
