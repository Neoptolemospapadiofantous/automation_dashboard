# End-to-end layer (Playwright)

Browser-level golden paths against a running app — the cross-system flows no
single unit covers. Config: `playwright.config.js`. See `tests/README.md` for
where this sits in the overall test architecture.

## Run locally

```bash
# One-time: install the browser binary.
npx playwright install chromium

# Drive an already-running dev server + a known active agent:
E2E_AGENT_SLUG=<active-agent-slug> pnpm test:e2e

# Or let Playwright start `php artisan serve` for you (omit E2E_NO_SERVER).
```

Env knobs:

| Var | Meaning | Default |
| --- | ------- | ------- |
| `E2E_BASE_URL` | app origin under test | `http://127.0.0.1:8000` |
| `E2E_AGENT_SLUG` | active agent slug to drive (smokes **skip** if unset) | — |
| `E2E_NO_SERVER` | reuse a running server instead of auto-starting one | — |

## CI

The `e2e` job (`.github/workflows/ci.yml`) boots MariaDB, migrates, runs
`database/seeders/E2eSeeder.php` to create a fixed-slug active agent, starts the
server, then runs these specs against it.

## Scope

`widget.smoke.spec.js` covers the **LLM-free** embed surface (widget loader JS,
iframe chat HTML, 404 on unknown slug) so it's deterministic and free. The
`/launch` and `/interact` runtime paths need a stubbed LLM and are added
separately rather than calling a live provider from CI.
