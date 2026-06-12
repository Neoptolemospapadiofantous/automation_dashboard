<?php

/*
|--------------------------------------------------------------------------
| SLA targets & CI performance budgets
|--------------------------------------------------------------------------
|
| PLACEHOLDER TARGETS — confirm with the team before production / Phase 12
| (see PROJECT_ASSURANCE_STRATEGY.md, Category G). Every key in this file
| must have a reader; do not add speculative keys (the dead-code discipline
| applies to config too).
|
| CI budgets are deliberately generous (2–3× looser than prod targets):
| GitHub runners are noisy, so these catch order-of-magnitude regressions,
| not fine tuning. Note the chat budget in particular: a real LLM turn is
| dominated by provider latency (seconds, outside our control) — in CI the
| provider is Http::fake'd, so the budget measures only our own pipeline
| (auth, billing, recording, broadcast) around the call.
|
| Readers:
|   ci_budget.dashboard_ms  → tests/Performance/BudgetTest.php
|   ci_budget.chat_turn_ms  → tests/Performance/BudgetTest.php
|   health.cache_key        → app/Http/Controllers/HealthController.php
*/

return [

    'ci_budget' => [
        // Authenticated dashboard page render (Inertia), ms.
        'dashboard_ms' => 2000,

        // chat.launch round trip with the LLM provider faked, ms.
        'chat_turn_ms' => 3000,
    ],

    'health' => [
        // Cache key used by the /api/health put/get roundtrip check.
        'cache_key' => 'health:check',
    ],

];
