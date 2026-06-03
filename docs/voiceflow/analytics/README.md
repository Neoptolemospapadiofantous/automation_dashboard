# Voiceflow Analytics API

Mirrored from `https://docs.voiceflow.com/api-reference/analytics-api/`.

The analytics surface bundles four groups on `https://analytics-api.voiceflow.com`, all authenticated with a Voiceflow workspace API key via the `authorization` header.

| File | Method | Path | Purpose |
|------|--------|------|---------|
| [overview.md](./overview.md) | — | — | Surface overview, host, auth, and how the analytics groups relate. |

## Endpoint groupings

- **Usage queries** — see `../usage/query-usage.md` (`POST /v2/query/usage`).
- **Transcript queries** (search/get/delete/end transcripts and properties/evaluations) — see `../transcripts/README.md`. These endpoints are part of the analytics API host but are filed under `transcripts/` to mirror Voiceflow's own product grouping.

## Notes / gaps

- The official analytics overview page on `docs.voiceflow.com` does not enumerate additional analytics-only endpoints beyond the usage query and the transcript group. If Voiceflow ships new analytics endpoints they will appear in `llms.txt` first.
