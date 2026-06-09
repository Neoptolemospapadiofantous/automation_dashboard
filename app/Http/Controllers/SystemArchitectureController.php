<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * Serves the in-app "API & Data Flow" documentation page. Renders Mermaid
 * diagrams of the actual codepaths so a new contributor (or the operator)
 * can navigate the system without reading the source tree.
 *
 * The diagrams here intentionally lag the code slightly — they're a
 * map, not the territory. Update when a flow changes shape, not when
 * a method renames.
 */
class SystemArchitectureController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('System/Architecture', [
            'flows' => $this->flows(),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function flows(): array
    {
        return [
            [
                'id' => 'chat-turn',
                'title' => '1 · Chat turn lifecycle (streaming)',
                'summary' => 'A single user message → SSE-streamed Voiceflow reply → local persistence + credit debit + lead upsert.',
                'source' => 'VoiceflowController::interactStream + StreamingClient',
                'diagram' => <<<'MMD'
sequenceDiagram
    participant U as Browser<br/>(Chat/Index.vue)
    participant LR as Laravel<br/>(VoiceflowController)
    participant RC as RuntimeClient
    participant SC as StreamingClient
    participant VF as Voiceflow<br/>(general-runtime)
    participant DB as Local DB

    U->>LR: POST /chat/interact/stream<br/>{user_id, message}
    LR->>RC: ensure session (cached 1h)
    alt session cache miss
        RC->>VF: POST /v4/.../session
        VF-->>RC: sessionKey
    end
    LR->>SC: streamInteract(...)
    SC->>VF: POST /v4/interact/stream (SSE)
    loop SSE frames
        VF-->>SC: event: trace · data: {...}
        SC-->>LR: yield $event
        LR-->>U: echo event: trace · data: {...} ; flush()
    end
    LR->>DB: insert messages (user + parsed agent traces)
    LR->>VF: GET /state/user/{id} (variables, 30s cache)
    VF-->>LR: {name, email, ...}
    LR->>DB: upsert lead
    LR->>DB: CreditMeter.consume(-1) ; CreditBurnAlert evaluate
    LR-->>U: event: summary · data: {messages_billed, lead_id, ended}
MMD,
                'notes' => [
                    'Session minting happens lazily and is cached on the cache driver for 1h per (project, env, user_id).',
                    'getVariables() has its own 30s cache to avoid doubling round-trips on every turn.',
                    'CreditMeter.consume runs inside the same DB::transaction as the message insert — atomic.',
                    'EvaluateCreditAlerts fires inside that same transaction; if it crosses 50/80/95% the owner gets a database+mail notification.',
                ],
            ],

            [
                'id' => 'lead-capture-webhook',
                'title' => '2 · Lead capture via inbound webhook',
                'summary' => 'Your Voiceflow Custom Action POSTs to us when the flow decides a lead is worth qualifying. Authenticated via per-agent secret.',
                'source' => 'VoiceflowWebhookController::leadCaptured',
                'diagram' => <<<'MMD'
sequenceDiagram
    participant VF as Voiceflow flow<br/>(Custom Action)
    participant LR as Laravel<br/>(VoiceflowWebhookController)
    participant Sec as Per-agent secret<br/>(agents.webhook_secret, encrypted)
    participant DEL as LeadDelegator
    participant DB as Local DB
    participant Ev as Echo broadcast

    VF->>LR: POST /api/voiceflow/lead-captured/{slug}<br/>X-Webhook-Secret · {name, email, qualified, score, ...}
    LR->>Sec: hash_equals(expected, provided)
    alt secret mismatch
        Sec-->>LR: false
        LR-->>VF: 401
    end
    LR->>LR: validate payload (422 on score type, missing name, etc.)
    LR->>DB: upsert Lead (name/email/phone/company/qualified/score)
    LR->>DEL: delegate(lead)
    DEL->>DB: assign to least-loaded team member<br/>+ audit row in lead_assignments
    LR->>Ev: LeadSaved + LeadAssigned broadcasts
    Ev-->>LR: kanban + bell update live
    LR-->>VF: 200 {ok: true, lead_id}
MMD,
                'notes' => [
                    'Source is normalised to "voiceflow" regardless of what the payload claims, so cross-source comparisons stay honest.',
                    'Score is integer-typed; the controller 422s if a string sneaks through.',
                    'Real-time delivery to /leads is via Echo / Reverb if configured; otherwise the kanban needs a manual refresh.',
                ],
            ],

            [
                'id' => 'session-lifecycle-webhook',
                'title' => '3 · Session-lifecycle webhook (transcript ID arrival)',
                'summary' => 'Voiceflow tells us a session ended; we stamp ended_at and link our local conversation to its upstream transcript ID.',
                'source' => 'Voiceflow\\SessionLifecycleController',
                'diagram' => <<<'MMD'
sequenceDiagram
    participant VF as Voiceflow runtime
    participant LR as Laravel<br/>(SessionLifecycleController)
    participant Sec as Per-agent secret
    participant DB as Local DB

    VF->>LR: POST /api/voiceflow/webhooks/session/{slug}<br/>X-Webhook-Secret · {event_type, event_id, payload}
    LR->>Sec: verify secret (401 on mismatch)
    LR->>DB: voiceflow_webhook_events: idempotent insert by (agent_id, event_id)
    alt event already processed
        DB-->>LR: duplicate row → 200 {ok, duplicate: true}
    end
    alt event_type = runtime.session.end
        LR->>DB: conversations.ended_at = now()
        LR->>DB: conversations.voiceflow_transcript_id = payload.transcript_id
    end
    LR->>DB: voiceflow_webhook_events.processed_at = now()
    LR-->>VF: 200 {ok: true, event_id}
MMD,
                'notes' => [
                    'Idempotency is enforced at the (agent_id, event_id) composite — Voiceflow retries land harmlessly.',
                    'The voiceflow_transcript_id field is what makes the "End upstream" button visible on /conversations/{id}.',
                ],
            ],

            [
                'id' => 'org-events-webhook',
                'title' => '4 · Org events (Svix HMAC)',
                'summary' => 'organization.project.* events are signed via Svix Standard Webhooks; we verify in-house, no svix/svix dep.',
                'source' => 'Voiceflow\\OrgEventsController + SvixVerifier',
                'diagram' => <<<'MMD'
sequenceDiagram
    participant VF as Voiceflow platform
    participant LR as Laravel<br/>(OrgEventsController)
    participant SV as SvixVerifier
    participant DB as Local DB

    VF->>LR: POST /api/voiceflow/webhooks/org<br/>svix-id · svix-timestamp · svix-signature
    LR->>SV: verify(headers, body, secret)
    SV->>SV: HMAC-SHA256({id}.{ts}.{body})
    SV->>SV: constant-time compare against each header signature
    SV->>SV: reject if timestamp outside 5-min replay window
    alt all signatures fail
        SV-->>LR: false → 401
    end
    LR->>DB: voiceflow_webhook_events: idempotent insert
    alt event_type = organization.project.deleted
        LR->>DB: voiceflow_project_pool: retire matching entries (driver-agnostic per-row update)
    end
    LR-->>VF: 200 {ok}
MMD,
                'notes' => [
                    'Falls back to a shared platform secret if VOICEFLOW_SVIX_SECRET is unset — same controller, different verifier branch.',
                    'Multi-signature header support means Voiceflow can rotate keys without downtime.',
                    'The driver-agnostic update for pool retirement was an audit fix — earlier DB::raw("|| ...") only worked on SQLite/Postgres.',
                ],
            ],

            [
                'id' => 'credit-flow',
                'title' => '5 · Credit consumption + burn alerts',
                'summary' => 'Every chat turn debits credits inside a single DB transaction. Threshold crossings (50/80/95% used) fire database + mail notifications, idempotent per billing period.',
                'source' => 'CreditMeter + EvaluateCreditAlerts + CreditBurnAlertNotification',
                'diagram' => <<<'MMD'
flowchart LR
    A[chat turn completes] --> B[CreditMeter.consume]
    B --> T{{DB::transaction}}
    T --> L[Team::lockForUpdate]
    L --> C{hasCredits?}
    C -- no --> X[throw OutOfCredits]
    C -- yes --> D[balance -= amount]
    D --> R[insert CreditTransaction row]
    R --> E[EvaluateCreditAlerts.evaluate]
    E --> F{newlyCrossed thresholds?}
    F -- none --> END[commit transaction]
    F -- 50/80/95 --> N[CreditBurnAlertNotification to owner]
    N --> END
    END --> Bell[bell UI badges]
    END --> Mail[email to owner]
MMD,
                'notes' => [
                    'Lock + check + debit + alert all run inside one DB::transaction → no race window where balance and fired-thresholds can drift.',
                    'Top-up that lifts balance above a threshold removes it from fired, so a drop-then-top-then-drop cycle re-fires correctly.',
                    'Monthly grant renewal hard-resets fired = [] — fresh period, fresh alerts.',
                    'Business plan (monthlyCredits = 0, negotiated) is exempt — no denominator for a meaningful percentage.',
                ],
            ],

            [
                'id' => 'auth-key-resolution',
                'title' => '6 · Auth key resolution',
                'summary' => 'Three distinct key types, three distinct surfaces. Misrouting is prevented by binding subclients to the correct key at construction time.',
                'diagram' => <<<'MMD'
flowchart LR
    A[Caller: I need to query Voiceflow] --> B{Which surface?}
    B -- interact / state / KB query --> RC[RuntimeClient<br/>uses DM key]
    B -- transcripts / evaluations / usage --> AC[AnalyticsClient<br/>uses workspace key]
    B -- KB CRUD / environments --> RTC[RealtimeClient<br/>uses workspace key]
    B -- /v4/interact/stream --> SC[StreamingClient<br/>uses sessionKey<br/>derived from DM key]

    RC --> KEY1[DM key VF.DM.*<br/>per project]
    AC --> KEY2[Workspace key<br/>cross-project, paid tier]
    RTC --> KEY2
    SC --> KEY3[sessionKey<br/>ephemeral, cached 1h<br/>minted via POST /v4/.../session]

    KEY1 -. encrypted at rest .-> DB1[agents.voiceflow_api_key]
    KEY2 -. encrypted at rest .-> DB2[agents.voiceflow_workspace_api_key]
    KEY3 -. cache only, never persisted .-> CACHE[Cache::store driver]
MMD,
                'notes' => [
                    'Analytics + Realtime clients used to throw in the constructor if workspace key was empty; now deferred to per-method workspaceClient() so draft agents render graceful empty states instead of 500s.',
                    'sessionKey isn\'t a "key" you store — it\'s a per-(project, env, user) handle Voiceflow gives back from session start. We re-mint on 401.',
                ],
            ],

            [
                'id' => 'kb-query',
                'title' => '7 · Knowledge base query (the "Ask" panel)',
                'summary' => 'A free-text question goes through Voiceflow\'s KB query endpoint and returns chunks scored by relevance.',
                'source' => 'KnowledgeBaseController::query + RuntimeClient::queryKnowledgeBase',
                'diagram' => <<<'MMD'
sequenceDiagram
    participant U as Browser<br/>(Knowledge/Index.vue · Ask panel)
    participant LR as Laravel<br/>(KnowledgeBaseController)
    participant RC as RuntimeClient
    participant VF as Voiceflow<br/>(general-runtime)

    U->>LR: POST /knowledge/query {question}
    LR->>RC: queryKnowledgeBase(question)
    RC->>VF: POST /v1alpha1/public/knowledge-base/query<br/>Authorization: DM key
    VF-->>RC: { output, chunks: [{score, content}, ...] }
    RC-->>LR: parsed payload
    LR-->>U: 200 { output, chunks }
    U->>U: render answer + top chunks with relevance bars
MMD,
                'notes' => [
                    'KB query uses the DM key, not the workspace key — that\'s why it can work on free Voiceflow plans.',
                    'KB CRUD (add/list/delete docs) uses workspace key on the realtime-api host — paid tier.',
                ],
            ],

            [
                'id' => 'error-mapping',
                'title' => '8 · HTTP error mapping',
                'summary' => 'Every Voiceflow response goes through VoiceflowHttpClient::ensureOk, which translates HTTP shapes into typed exceptions controllers can pattern-match on.',
                'source' => 'VoiceflowHttpClient::ensureOk + exception hierarchy',
                'diagram' => <<<'MMD'
flowchart TD
    R[Voiceflow response]
    R --> S{response.successful?}
    S -- yes --> OK[return response<br/>caller parses ->json]
    S -- no --> CODE{status}
    CODE -- 401/403 --> A[throw AuthException<br/>controller may re-mint session, retry once]
    CODE -- 404 --> NF[throw NotFoundException]
    CODE -- 429 --> RL[throw RateLimitedException<br/>carries retryAfter from Retry-After header]
    CODE -- 5xx --> UP[throw UpstreamException]
    CODE -- other --> UP

    A -.extends.-> V[VoiceflowException]
    NF -.extends.-> V
    RL -.extends.-> V
    UP -.extends.-> V

    V --> CTRL[Controllers catch (VoiceflowException) → graceful UI fallback<br/>or rethrow for 5xx-shape JSON response]
MMD,
                'notes' => [
                    'All four typed exceptions extend VoiceflowException, so callers can catch the base for "any Voiceflow problem" or be specific.',
                    'KbUploadException + MisconfiguredException are siblings — same base, different domain (pre-flight validation, not HTTP).',
                    'ensureOk also emits structured logs via Log::channel("voiceflow") with the endpoint label, status, and context — visible in storage/logs/voiceflow.log.',
                ],
            ],
        ];
    }
}
