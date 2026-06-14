# Laravel Project Assurance Strategy — Complete Specification (v3)

> **Purpose:** Implementation spec for a project agent. This is the single source of truth — it supersedes all earlier versions. Execute category by category, phase by phase (§I6).
> **Context:** Laravel project originally built around the Voiceflow service (git/production branch), restructured locally to run **without** Voiceflow. The suite must guarantee: (1) the local implementation never silently diverges from the conversational contract, (2) every commit proves the app is fully wired and connected, (3) regressions, structural rot, security holes, and SLA breaches are caught at the cheapest possible stage.
> **Conditional sections:** items marked **[IF-UI]**, **[IF-QUEUE]**, **[IF-I18N]**, **[IF-API-CONSUMERS]** apply only when the project has that surface. The agent must inspect the codebase, decide applicability, and record the decision in CONTRIBUTING.md rather than silently skipping.

---

# CATEGORY MAP

| Cat | Concern | Where it runs |
|-----|---------|---------------|
| **A. Code Quality & Structure** | style, types, architecture rules, mutation testing, dead code | CI, pre-commit |
| **B. Functional Correctness** | unit, feature/HTTP, validation, regressions | CI |
| **C. Behavioral Parity (Voiceflow ↔ Local)** | contract tests, snapshots, parity gate | CI |
| **D. Data & System Integrity** | factories, seeders, migrations, schema, routes, scheduler | CI |
| **E. Wiring & Connectivity** | container graph, bindings, events↔listeners, routes↔controllers, config/env contract, boot | CI |
| **F. Security & Access Control** | authorization, rate limits, headers, secrets, dependencies, PII | CI + repo scanning |
| **G. SLA: Performance, Resilience & Availability** | budgets, load tests, resilience, queues, monitoring | CI + staging + production |
| **H. API Contract, Deployment & Release** | OpenAPI, zero-downtime migrations, post-deploy smoke, rollback | CI + deploy pipeline |
| **I. Pipeline, Workflow & Governance** | CI order, hooks, conventions, coverage ratchet, definition of done | repo/CI |

---

# CATEGORY A — CODE QUALITY & STRUCTURE

## A1. Tooling

```bash
composer require --dev pestphp/pest pestphp/pest-plugin-laravel
php artisan pest:install
composer require --dev larastan/larastan laravel/pint
composer require --dev infection/infection
composer require --dev spatie/pest-plugin-snapshots
composer require --dev brainmaestro/composer-git-hooks
```

## A2. Configuration

**`phpstan.neon`** — level 6, ratchet upward toward 8 (level 8 verifies `app(X::class)` generics — it does wiring work too); paths: `app`, `database`, `routes`. Baseline allowed for legacy violations, shrink-only.

**`pint.json`** — laravel preset + `declare_strict_types`, alpha-ordered imports, no unused imports.

**`infection.json5`** — source: `app/Domain`, `app/Services`, `app/Contracts`; `minMsi: 70`, `minCoveredMsi: 80`.

**Test env:** SQLite in-memory (or dedicated test DB), `APP_ENV=testing`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`, `CACHE_STORE=array`.

## A3. Architecture tests — `tests/Arch/ArchitectureTest.php`

```php
arch('no debugging leftovers')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'exit', 'die'])->not->toBeUsed();

arch('domain layer is framework-agnostic')
    ->expect('App\Domain')->not->toUse(['Illuminate\Http', 'Illuminate\Support\Facades']);

arch('controllers stay thin')
    ->expect('App\Http\Controllers')->not->toUse('Illuminate\Database\Eloquent\Builder');

arch('contracts are interfaces')
    ->expect('App\Contracts')->toBeInterfaces();

arch('conversation engines implement the contract')
    ->expect('App\Services\Conversation')->toImplement('App\Contracts\ConversationEngine');

arch('strict types everywhere')
    ->expect('App')->toUseStrictTypes();

arch('no env() outside config')
    ->expect('App')->not->toUse('env');

// Wiring support (see Category E): ban string-based resolution so PHPStan/IDE can verify references
arch('no string-based container or event resolution')
    ->expect('App')->not->toUse(['resolve']); // and review app('string') usages manually; prefer ::class
```

Adapt namespaces to the real codebase; create `App\Domain` and migrate pure business logic into it if absent.

## A4. Mutation testing (tests-for-the-tests)

`vendor/bin/infection --min-msi=70 --threads=max` — weekly scheduled CI job, scoped to domain/services/contracts. Surviving mutants in state-machine logic = bugs in the tests; fix the tests.

---

# CATEGORY B — FUNCTIONAL CORRECTNESS

## B1. Test directory layout

```
tests/
├── Arch/            # A
├── Contracts/       # C
├── Unit/            # B2
├── Feature/         # B3 (Api/, Auth/, Conversation/)
├── Snapshots/       # C
├── Integrity/       # D
├── Wiring/          # E
├── Security/        # F
├── Performance/     # G (budgets, resilience)
└── Pest.php
```

## B2. Unit tests — pure logic, no DB/HTTP/filesystem

- Intent parsing / input classification.
- Dialog state machine: every state × every event; invalid transitions throw.
- Value objects & DTOs: immutability, equality, serialization round-trip.
- Validators, normalizers, domain calculations.
- No `RefreshDatabase`; freeze time wherever time matters; exhaustive branch coverage here.

## B3. Feature / HTTP tests

Conventions: `RefreshDatabase` for the directory; all data via factories; `Http::fake()` + `Http::preventStrayRequests()` global (suite passes offline); `Queue/Event/Notification/Mail::fake()` with explicit dispatch assertions.

Required coverage:
1. Conversation round trip → well-formed JSON (`assertJsonStructure`).
2. Auth boundaries: every protected endpoint → 401/403 (overlaps F1; write once, count for both).
3. Validation: invalid payloads → 422 with expected error keys.
4. Error handling: engine failure → clean JSON error, never a stack trace.
5. Idempotency: duplicate message submission handled per spec.
6. Regression flow: every bug fix lands with `it('regression: <issue ref> ...')` reproducing it first.

## B4. Query efficiency guards

`Model::shouldBeStrict(! app()->isProduction());` in `AppServiceProvider::boot()` — N+1s, discarded attributes, missing-attribute access become test failures. Plus bounded-query assertions on hot endpoints.

---

# CATEGORY C — BEHAVIORAL PARITY (VOICEFLOW ↔ LOCAL)

**Highest-priority category** — insurance that the local restructure never diverges from the Voiceflow contract.

## C1. Contract interface — `app/Contracts/ConversationEngine.php`

```php
interface ConversationEngine
{
    public function interact(string $sessionId, UserInput $input): ConversationReply;
    public function launch(string $sessionId, array $context = []): ConversationReply;
    public function state(string $sessionId): SessionState;
    public function end(string $sessionId): void;
}
```

Immutable DTOs: `UserInput`, `ConversationReply` (messages, choices, context, ended), `SessionState`.
Implementations: `LocalConversationEngine` (restructured logic) and `VoiceflowEngine` (thin HTTP adapter for the git/prod branch — always behind `Http`, never hit live in tests). Bound via `config('conversation.driver')`.

## C2. Shared contract test — Pest dataset across all engines

Behaviors (one test each):
1. `launch()` returns a non-empty greeting.
2. `interact()` returns ≥1 message for any valid input.
3. Session state persists across turns.
4. Garbage input → graceful fallback, never an exception.
5. Context set in one turn is readable in the next.
6. `end()` terminates; post-end `interact()` behavior explicitly specified and encoded.
7. Concurrent sessions don't leak state.
8. Reply payload shape stable (messages, choices, context, ended).

## C3. Snapshot tests for dialog paths — `tests/Snapshots/`

5–15 critical dialog paths; script the full turn sequence; `assertMatchesJsonSnapshot` on complete reply payloads; normalize volatile fields (timestamps, UUIDs). Updates only via `--update-snapshots` with diff reviewed in PR.

## C4. Parity gate

Before any merge between local and Voiceflow branches (either direction): full contract dataset green for **both** engines.

---

# CATEGORY D — DATA & SYSTEM INTEGRITY

**`tests/Integrity/`:**

1. **Factories:** every factory produces a valid, persistable model.
2. **Seeders:** `db:seed` runs cleanly in testing.
3. **Migrations round-trip:** `migrate:fresh` then `migrate:rollback` succeed.
4. **Schema guards:** foreign keys actually declared; unique constraints exist where domain requires uniqueness (assert via `Schema::getIndexes()` on critical tables — e.g. session id, user email).
5. **Route smoke:** every registered GET route returns no 500.
6. **Scheduler:** `schedule:list` runs; every scheduled command exists and is invokable.
7. **Model casts/columns coherence:** for each model, every `$casts`/`$fillable` key exists as a column (catches drift after migrations).
8. **[IF-I18N] Translation completeness:** every `__()`/`trans()` key used in `app/` and `resources/` exists in every supported locale file; no locale missing keys another has.

---

# CATEGORY E — WIRING & CONNECTIVITY

**Every commit must prove the app is fully wired.** Laravel's container, events, routes, and config are stringly-typed and resolved at runtime — this category makes resolution failures a CI failure, not a production incident. **`tests/Wiring/`:**

## E1. Container resolution

```php
it('resolves every registered binding', function () {
    foreach (array_keys(app()->getBindings()) as $abstract) {
        expect(fn () => app()->make($abstract))->not->toThrow(Throwable::class, $abstract);
    }
});

it('binds the conversation engine to the configured driver', function () {
    config(['conversation.driver' => 'local']);
    expect(app(ConversationEngine::class))->toBeInstanceOf(LocalConversationEngine::class);
});
```

Add one pinned assertion per critical contract (engine, repositories, gateways).

## E2. Constructor graph resolution (implicit autowiring)

Force-resolve every injectable class — if `app()->make()` succeeds for all, the dependency graph is constructible:

```php
dataset('injectables', function () {
    $dirs = ['Http/Controllers', 'Jobs', 'Listeners', 'Console/Commands', 'Services'];
    return collect($dirs)->flatMap(fn ($d) =>
        collect(File::allFiles(app_path($d)))
            ->map(fn ($f) => 'App\\' . str_replace(['/', '.php'], ['\\', ''], $f->getRelativePathname()))
            ->map(fn ($c) => str_replace('App\\', 'App\\' . str_replace('/', '\\', $d) . '\\', basename($c)) === $c ? $c : 'App\\' . str_replace('/', '\\', $d) . '\\' . class_basename($c))
    )->filter(fn ($c) => class_exists($c) && ! (new ReflectionClass($c))->isAbstract())->values();
});
// Agent: implement the class-discovery helper cleanly (composer classmap or Symfony Finder) — the intent is: every concrete class in those dirs resolves.

it('can construct every injectable class', function (string $class) {
    expect(fn () => app()->make($class))->not->toThrow(Throwable::class);
})->with('injectables');
```

## E3. Event → listener map

```php
it('every registered listener exists', function () {
    foreach (app(Illuminate\Contracts\Events\Dispatcher::class)->getRawListeners() as $event => $listeners) {
        foreach ($listeners as $listener) {
            $class = is_string($listener) ? explode('@', $listener)[0] : null;
            if ($class) expect(class_exists($class))->toBeTrue("Listener {$class} for {$event} missing");
        }
    }
});
```

Plus the inverse — pin that critical events have their listeners attached (renamed listeners silently detaching is a classic wiring regression):

```php
it('ConversationEnded has its transcript listener', function () {
    expect(Event::hasListeners(ConversationEnded::class))->toBeTrue();
    // and assert PersistTranscript specifically appears in the raw listener list
});
```

## E4. Routes ↔ controllers ↔ middleware (static pass)

```php
it('every route action exists and middleware resolves', function () {
    foreach (Route::getRoutes() as $route) {
        if ($controller = $route->getControllerClass()) {
            expect(method_exists($controller, $route->getActionMethod()))->toBeTrue(
                "Route {$route->uri()} → missing {$controller}::{$route->getActionMethod()}");
        }
        foreach ($route->gatherMiddleware() as $mw) {
            $alias = explode(':', $mw)[0];
            $resolved = app('router')->getMiddleware()[$alias] ?? $alias;
            if (is_string($resolved) && ! class_exists($resolved)) {
                test()->fail("Middleware {$mw} on {$route->uri()} doesn't resolve");
            }
        }
    }
});

it('all referenced named routes exist', function (string $name) {
    expect(Route::has($name))->toBeTrue("Named route {$name} missing");
})->with(['conversation.interact', 'conversation.launch' /* every name code/frontend depends on */]);
```

## E5. Config & environment contract

```php
it('required config keys are present and non-null', function (string $key) {
    expect(config($key))->not->toBeNull("Missing config: {$key}");
})->with(['conversation.driver', 'conversation.session_ttl', 'sla.ci_budget.turn_ms' /* every key the app reads */]);

it('.env.example covers every env var referenced in config/', function () {
    $referenced = collect(File::allFiles(config_path()))
        ->flatMap(fn ($f) => Str::matchAll("/env\(['\"]([A-Z0-9_]+)['\"]/", $f->getContents()))->unique();
    $example = collect(file(base_path('.env.example')))
        ->map(fn ($l) => Str::before(trim($l), '='))->filter();
    expect($referenced->diff($example)->values()->all())->toBeEmpty();
});
```

This guarantees a fresh clone + `.env.example` copy boots — wiring at the deployment level.

## E6. Boot test

```php
it('boots the full application with all providers', function () {
    expect(app()->isBooted())->toBeTrue();
    $this->artisan('about')->assertSuccessful(); // touches config, drivers, providers
});
```

Catches provider exceptions, circular dependencies, and bad config at the earliest moment.

---

# CATEGORY F — SECURITY & ACCESS CONTROL

**`tests/Security/`** + repo-level scanning:

## F1. Authorization (policies/gates)

- One test per policy ability: owner allowed, non-owner forbidden (403), guest unauthenticated (401).
- Cross-tenant/cross-user isolation: user A can never read/mutate user B's conversations/sessions — assert at the HTTP layer, not just policy unit level.
- Wiring overlap: every model with a policy is registered (`Gate::getPolicyFor($model)` not null).

## F2. Input & mass-assignment safety

- Arch/unit guard: no model uses `$guarded = []`; `$fillable` is explicit.
- Over-posting test per write endpoint: send extra privileged fields (`is_admin`, `user_id`) → assert they are ignored.
- File upload endpoints (if any): reject oversized/wrong-MIME payloads.

## F3. Rate limiting & abuse

```php
it('throttles the conversation endpoint', function () {
    $user = User::factory()->create();
    $limit = config('sla.rate_limit.turns_per_minute', 30);
    for ($i = 0; $i < $limit; $i++) {
        $this->actingAs($user)->postJson('/api/conversation', ['message' => 'x'])->assertOk();
    }
    $this->actingAs($user)->postJson('/api/conversation', ['message' => 'x'])->assertStatus(429);
});
```

Rate limits are also SLA protection (one user can't burn the error budget).

## F4. Transport & headers

- Feature test asserting security headers on responses: `X-Content-Type-Options`, `X-Frame-Options`/CSP, `Referrer-Policy` (via middleware the agent adds if absent).
- Session/CSRF: web routes reject missing CSRF token; cookies are `HttpOnly`, `Secure`, `SameSite` per config — assert config values in the config-contract test (E5).

## F5. Secrets & dependency hygiene (repo level)

- **Secrets scanning:** gitleaks (or equivalent) job in CI on every push; fails on committed credentials, including Voiceflow API keys from the original branch history going forward.
- **`composer audit`** every CI run (already in pipeline); **Dependabot/Renovate** enabled for composer + npm + GitHub Actions, weekly.
- `.env`, `.env.*` (except `.env.example`) confirmed in `.gitignore` — assert via a tiny CI script.

## F6. PII & logging discipline

- The structured per-turn logging (G5) must log session id and metadata — never raw message content at default log level. Add a unit test on the log formatter/context builder asserting message bodies are absent/redacted.
- Exception handler test: validation/auth exceptions don't leak internals (no SQL, no paths) in production render mode.

---

# CATEGORY G — SLA: PERFORMANCE, RESILIENCE & AVAILABILITY

Three defense lines: **G1–G3 in CI**, **G4 staging**, **G5–G6 production**.

> **Placeholder targets** — agent: centralize in `config/sla.php`, confirm with team before Phase 12:
> turn API p95 < 500 ms / p99 < 1200 ms · launch p95 < 800 ms · reads p95 < 300 ms · queued jobs < 5 s, max 3 retries · availability 99.5%/month (error budget ≈ 3.6 h) · 50 concurrent sessions, 20 req/s sustained · rate limit 30 turns/min/user.

## G1. Performance budget tests (CI) — `tests/Performance/BudgetTest.php`

One budget test per SLA-relevant endpoint; CI budgets 2–3× looser than prod targets (CI runners are noisy) — they catch order-of-magnitude regressions, not fine tuning:

```php
it('conversation turn responds within CI budget', function () {
    $user = User::factory()->create();
    $start = hrtime(true);
    $this->actingAs($user)->postJson('/api/conversation', ['message' => 'hello'])->assertOk();
    expect((hrtime(true) - $start) / 1e6)->toBeLessThan(config('sla.ci_budget.turn_ms', 1500));
});
```

## G2. Query & payload budgets (CI)

Bounded query counts on hot endpoints; pagination guards on list endpoints (assert `assertJsonCount(page_size, 'data')` + `links`/`meta` structure against 200+ seeded rows).

## G3. Resilience tests (CI) — `tests/Performance/ResilienceTest.php`

All via fakes, suite stays offline:
1. External dependency timeout/500 (prod: Voiceflow; local: any third-party) → clean 502/503 JSON with retry-after, no stack trace, incident logged.
2. Cache down → app serves degraded, no 500.
3. DB degraded → `/health` reports it correctly.
4. **[IF-QUEUE]** Worker dead → user-facing flow returns specified fallback, never hangs.
5. Malformed engine output → reply-validation layer converts to fallback + alert log, never a crash.

## G4. Load & stress (staging, weekly + pre-release) — k6

`tests/load/conversation.js` with thresholds = the actual SLA numbers (threshold breach fails the run):

```javascript
export const options = {
  scenarios: {
    sustained: { executor: 'constant-arrival-rate', rate: 20, timeUnit: '1s', duration: '5m', preAllocatedVUs: 50 },
    spike: { executor: 'ramping-arrival-rate', startRate: 5,
             stages: [{ target: 100, duration: '1m' }, { target: 5, duration: '2m' }],
             preAllocatedVUs: 150, startTime: '6m' },
  },
  thresholds: { http_req_duration: ['p(95)<500', 'p(99)<1200'], http_req_failed: ['rate<0.01'] },
};
```

Maintain four scenarios: sustained, spike, **soak** (30 min at expected load — leaks/connection exhaustion), and **multi-turn session** (launch → 5 turns → end — load-tests state persistence, not just stateless posts).

## G5. Health endpoint & production monitoring

- `/health` (unauthenticated, cheap): db/cache/queue checks → 200 ok / 503 degraded; feature-tested.
- **Laravel Pulse** for SLIs: slow requests/queries/jobs, exceptions.
- External uptime check on `/health`, alert at 2 consecutive failures.
- Alerts: 5xx rate > 1% over 5 min; p95 > target for 10 min.
- Structured per-turn logging: session id, engine driver, duration ms, outcome (content-redacted per F6) — raw material for SLI dashboards and production parity diagnosis.

## G6. **[IF-QUEUE]** Queue/job SLA

Jobs declare `tries`/`backoff`/`timeout` matching `config/sla.php` (asserted in a test); failed-job path → dead-letter handling + user-facing fallback (tested); queue-lag alerting in production (jobs waiting > N s = SLA risk).

---

# CATEGORY H — API CONTRACT, DEPLOYMENT & RELEASE

## H1. **[IF-API-CONSUMERS]** OpenAPI contract

If a frontend/mobile/third party consumes the API:
- Maintain `openapi.yaml` in-repo as the source of truth.
- Contract validation tests: each feature test response validated against the schema (e.g. `osteel/openapi-httpfoundation-testing` or kirschbaum response-schema assertion).
- Breaking-change gate: CI diffs `openapi.yaml` against the base branch (`oasdiff`); breaking changes require an explicit version bump + label.

## H2. Zero-downtime migration safety

CI check (script or `migration-lint` style review rule) blocking in one deploy: dropping/renaming columns or tables still referenced by current code. Destructive changes follow the two-step expand→contract pattern across releases. Encode as a CI script scanning new migration files for `dropColumn`/`renameColumn`/`drop` + a CONTRIBUTING rule.

## H3. Post-deploy smoke (every deploy, against production)

Small idempotent script (Artisan command or shell) run by the deploy pipeline immediately after release:
1. `/health` → 200.
2. Launch + one interact turn on a dedicated smoke session → valid reply shape.
3. One authenticated read endpoint → 200.
Failure → automatic rollback (or page on-call if rollback isn't automated).

## H4. Rollback & recovery verification

- Documented rollback procedure (previous release + `migrate:rollback` only when safe per H2).
- Backup restore drill: scheduled (quarterly minimum) restore of the DB backup into staging + integrity suite (Category D) run against it. An untested backup is not a backup.

## H5. **[IF-UI]** Browser/E2E layer

If the project serves Blade/Inertia/Livewire UI: Laravel Dusk (or Playwright) suite covering only the 3–5 critical user journeys (login → start conversation → exchange turns → see history). Runs on PRs touching `resources/` and pre-release — not every commit.

---

# CATEGORY I — PIPELINE, WORKFLOW & GOVERNANCE

## I1. Determinism rules (every test)

Frozen time (`Carbon::setTestNow()`/`travelTo`); seeded/injected randomness; queues/events/mail/notifications always faked with explicit assertions; `Http::preventStrayRequests()` global; suite passes `--order-by=random` (enforced in CI); flaky test = P1, quarantine via `->skip()` + issue link, max one week.

## I2. CI pipeline

```yaml
name: CI
on: [push, pull_request]
jobs:
  quality:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: gitleaks/gitleaks-action@v2            # F5: secrets
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.3', coverage: pcov }
      - run: composer install --no-interaction --prefer-dist
      - run: composer validate --strict               # I: dependency sanity
      - run: composer audit                           # F5: CVEs
      - run: vendor/bin/pint --test                   # A: style
      - run: vendor/bin/phpstan analyse               # A: static analysis
      - run: php artisan test --parallel --compact --order-by=random  # B,C,D,E,F,G1–G3
      - run: ./bin/check-migrations-safety.sh         # H2

  mutation:   # A4 — weekly schedule
    if: github.event_name == 'schedule'
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.3', coverage: pcov }
      - run: composer install --no-interaction
      - run: vendor/bin/infection --min-msi=70 --threads=max

  load:       # G4 — weekly + release tags, staging only
    if: github.event_name == 'schedule' || startsWith(github.ref, 'refs/tags/')
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: grafana/setup-k6-action@v1
      - run: k6 run tests/load/conversation.js
        env: { BASE_URL: ${{ secrets.STAGING_URL }}, TOKEN: ${{ secrets.STAGING_TOKEN }} }
```

Add weekly `schedule:` trigger. Dependabot/Renovate config committed (F5).

## I3. Git hooks

pre-commit: `pint --test --dirty` + `phpstan analyse`; pre-push: `php artisan test --parallel --compact`. Install via `vendor/bin/cghooks add`.

## I4. Coverage ratchet

Once the suite is mature (not day one): `--coverage --min=80`; the floor may only rise. Track per-directory coverage for `app/Domain` and `app/Services` separately with a higher floor (90%).

## I5. Workflow conventions

1. **Bug fix:** failing regression test → fix → committed together; test names the issue.
2. **New feature:** unit tests for domain logic + ≥1 feature test per endpoint + snapshot if it adds a dialog path + budget test if SLA-relevant + policy test if it adds an ability + wiring pins if it adds bindings/events/named routes.
3. **Snapshots:** updated only via `--update-snapshots`, diff reviewed.
4. **PHPStan baseline:** shrink-only.
5. **Parity gate (C4):** both engines green before cross-branch merges.
6. **SLA changes:** `config/sla.php` edits update k6 thresholds + budget tests + rate-limit tests in the same PR.
7. **Release gate:** tags only after load job (G4) passes; deploy completes only after smoke (H3) passes.
8. **Conditional decisions:** every [IF-*] applicability decision recorded in CONTRIBUTING.md.

## I6. Execution order

| Phase | Deliverable | Cat | Priority |
|-------|------------|-----|----------|
| 1 | Tooling + config files (incl. `config/sla.php` skeleton) | A | Critical |
| 2 | Architecture tests | A | High |
| 3 | ConversationEngine contract + contract tests | C | **Highest** |
| 4 | Wiring suite (container, graph, events, routes, config/env, boot) | E | **Highest** |
| 5 | Unit tests (state machine, parsing, VOs) | B | High |
| 6 | Feature tests + strict models + query guards | B | High |
| 7 | Security suite (policies, mass assignment, throttle, headers) + gitleaks + Dependabot | F | High |
| 8 | Snapshot tests for dialog paths | C | High |
| 9 | Integrity suite (factories, seeders, migrations, schema, routes, scheduler, casts) | D | Medium |
| 10 | Budget + resilience tests; `/health` endpoint | G | High |
| 11 | CI pipeline + git hooks + migration-safety script | I, H | Critical |
| 12 | k6 load suite (staging) + release gate + post-deploy smoke | G, H | High |
| 13 | Production monitoring: Pulse, uptime, alerts, structured redacted logging | G | High |
| 14 | [IF-*] conditional layers: OpenAPI contract, Dusk E2E, i18n completeness, queue SLA | H, D, G | Medium |
| 15 | Mutation testing (scheduled) + coverage ratchet | A, I | Medium |
| 16 | Rollback/backup restore drill documented + first drill executed | H | Medium |

## I7. Definition of Done — master checklist

**A — Quality & Structure**
- [ ] All packages installed; `phpstan.neon` (≥ level 6), `pint.json`, `infection.json5` committed.
- [ ] Arch tests pass (incl. no string-based resolution); violations refactored or baselined.
- [ ] Mutation job scheduled; MSI ≥ 70 on scoped dirs.

**B — Correctness**
- [ ] Unit layer covers state machine transitions exhaustively.
- [ ] Feature layer: round trip, validation, error handling, idempotency.
- [ ] `Model::shouldBeStrict()` on; no lazy-loading violations.
- [ ] `Http::preventStrayRequests()` active; suite passes offline.

**C — Parity**
- [ ] `ConversationEngine` interface + DTOs; local implementation bound via config.
- [ ] Contract test: all 8 behaviors green for the local engine.
- [ ] Snapshots for all critical dialog paths committed.
- [ ] Parity gate in CONTRIBUTING.

**D — Integrity**
- [ ] Factories, seeders, migration round-trip, schema/index guards, route smoke, scheduler, casts-coherence all green.
- [ ] [IF-I18N] translation completeness test in place.

**E — Wiring**
- [ ] Container resolution test green for all bindings; critical contracts pinned.
- [ ] Constructor graph test covers controllers, jobs, listeners, commands, services.
- [ ] Event↔listener existence both directions (listeners exist; critical events have listeners).
- [ ] Route actions + middleware static pass; named-route pins for every referenced name.
- [ ] Config-keys test + `.env.example` parity test green.
- [ ] Boot test (`artisan about`) green.

**F — Security**
- [ ] Policy tests per ability incl. cross-user isolation; all policied models registered.
- [ ] No `$guarded = []`; over-posting tests on write endpoints.
- [ ] Throttle test per public/abuse-prone endpoint, limits from `config/sla.php`.
- [ ] Security headers + CSRF/session config asserted.
- [ ] gitleaks in CI; Dependabot/Renovate enabled; `.env*` gitignore asserted.
- [ ] Log redaction test (no message bodies); exception handler leaks nothing in production mode.

**G — SLA**
- [ ] `config/sla.php` with all targets, confirmed by team.
- [ ] Budget tests per SLA endpoint (CI budgets 2–3×); pagination/query budgets on hot paths.
- [ ] Resilience suite: dependency timeout, cache down, DB degraded, dead queue, malformed engine output.
- [ ] k6: sustained, spike, soak, multi-turn session — thresholds = SLA numbers; wired to schedule + tags.
- [ ] `/health` live + tested; uptime check; 5xx-rate and p95 alerts; Pulse installed; redacted structured per-turn logging.
- [ ] [IF-QUEUE] tries/backoff/timeout asserted; dead-letter fallback tested; queue-lag alert.

**H — Contract, Deploy & Release**
- [ ] [IF-API-CONSUMERS] `openapi.yaml` + response-schema validation + breaking-change diff gate.
- [ ] Migration-safety check in CI; expand→contract rule in CONTRIBUTING.
- [ ] Post-deploy smoke wired into the deploy pipeline with rollback/paging on failure.
- [ ] Rollback procedure documented; first backup-restore drill executed against staging.
- [ ] [IF-UI] Dusk/Playwright journeys for critical flows.

**I — Pipeline & Governance**
- [ ] CI in specified order; random test order on; weekly schedule for mutation + load.
- [ ] Pre-commit/pre-push hooks installed.
- [ ] Coverage ratchet plan documented (activate at maturity).
- [ ] All workflow conventions + [IF-*] decisions in README/CONTRIBUTING.

---

**Agent instruction:** work through phases in order; do not start a later phase until the earlier phase's checklist items are checked. Inspect the codebase to resolve every [IF-*] marker and record the decision. Adapt all namespaces, route names, models, and SLA numbers to the actual codebase and team targets — this document is the spec, not literal file contents. All placeholder SLA thresholds must be confirmed before Phase 12. Where example code in this spec is sketch-level (e.g. E2 class discovery), implement it cleanly rather than copying verbatim.
