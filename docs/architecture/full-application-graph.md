# Architecture — full application graph

A single end-to-end map of the whole application: from inbound HTTP and the
embed widget, through controllers, state machines, the native LLM runtime,
billing, and domain events, down to persistence and back out to the Inertia/Vue
frontend and real-time broadcasts.

> Companion to [[integration-map]] (surface-to-surface wiring),
> [[state-machines]] (transition rules), and [[data-model]] (table shapes).
> This doc is the *whole-system* lens — every layer in one graph.

> **Live version:** a local-only operator page renders this as an interactive
> 3D force-directed graph at `/architecture` (auto-discovered from the codebase,
> not hand-drawn — `app/Support/ArchitectureGraph`). Its integrity is gated by
> `php artisan arch:graph-check` (runs in `composer hermes-fast`), which fails if
> the node count drifts from the `app/` classes on disk or any node is orphaned.
> The Mermaid blocks below are the curated 2D companion view.

## 1. End-to-end system graph

```mermaid
flowchart TB
    %% ---------- Clients ----------
    subgraph clients[Clients]
        browser[Dashboard browser<br/>Inertia/Vue SPA]
        widget[Embedded chat widget<br/>3rd-party sites]
        stripe_cb[Stripe webhooks]
        cron[Scheduler / cron]
    end

    %% ---------- HTTP edge ----------
    subgraph http[HTTP edge - routes + middleware]
        web[routes/web.php]
        api[routes/api.php]
        channels[routes/channels.php]
        mw_inertia[HandleInertiaRequests]
        mw_agent[RequireAgent]
        mw_coming[ComingSoon]
        mw_sec[SecurityHeaders]
    end

    %% ---------- Controllers ----------
    subgraph controllers[Controllers]
        c_dash[DashboardController]
        c_lead[LeadController]
        c_convo[ConversationController]
        c_chat[ChatController]
        c_agent[AgentController]
        c_kb[KnowledgeController]
        c_bill[BillingController]
        c_embed[EmbedController]
        c_stripe[StripeWebhookController]
    end

    %% ---------- Domain services / state machines ----------
    subgraph domain[Domain services and state machines]
        sm_lead[LeadStateMachine]
        sm_agent[AgentStateMachine]
        sm_convo[ConversationStateMachine]
        delegator[LeadDelegator<br/>assignment strategies]
        recorder[ConversationRecorder]
        backstop[CaptureLeadBackstop]
    end

    %% ---------- Runtime engine ----------
    subgraph runtime[Native LLM runtime]
        agentrt[AgentRuntime]
        flow[FlowExecutor<br/>LeadCaptureFlow]
        router[LlmRouter]
        clients_llm[Anthropic / OpenAI / Gemini clients]
        tools[ToolRegistry<br/>capture_lead · query_kb · set_variable<br/>request_handoff · end_session]
        kb[KnowledgeBase / RAG]
    end

    %% ---------- Billing ----------
    subgraph billing[Billing]
        meter[CreditMeter]
        plan[Plan enum / limits]
        stripecli[StripeClient]
    end

    %% ---------- Events ----------
    subgraph events[Events and broadcasts]
        ev_state[StateChanged + typed domain events<br/>LeadQualified/Assigned/Won/Lost]
        ev_broadcast[LeadSaved · LeadMessage<br/>→ team.team_id channel]
        listeners[Listeners]
    end

    %% ---------- Persistence ----------
    subgraph data[Persistence - MariaDB + Typesense]
        db[(MariaDB<br/>teams · agents · leads · conversations<br/>messages · credit_transactions · runtime_sessions)]
        ts[(Typesense<br/>kb_chunks vector index)]
    end

    %% ---------- Flows ----------
    browser --> web
    widget --> c_embed
    widget --> api
    stripe_cb --> c_stripe
    cron --> runtime

    web --> mw_inertia --> controllers
    web --> mw_agent
    web --> mw_coming
    api --> mw_sec
    channels --> ev_broadcast

    c_dash --> db
    c_lead --> sm_lead
    c_lead --> delegator
    c_convo --> db
    c_chat --> agentrt
    c_agent --> sm_agent
    c_kb --> kb
    c_bill --> meter
    c_bill --> stripecli
    c_embed --> agentrt
    c_stripe --> meter

    agentrt --> flow
    flow --> router
    flow --> tools
    router --> clients_llm
    tools --> kb
    tools --> recorder
    tools --> backstop
    kb --> ts
    agentrt --> meter

    recorder --> sm_convo
    recorder --> db
    delegator --> db
    sm_lead --> ev_state
    sm_agent --> ev_state
    sm_convo --> ev_state
    sm_lead --> db
    sm_agent --> db
    sm_convo --> db

    ev_state --> listeners
    listeners --> ev_broadcast
    recorder --> ev_broadcast
    ev_broadcast -.websocket.-> browser

    meter --> db
    stripecli --> db

    controllers -.Inertia props.-> browser
```

## 2. Request lifecycles (the three hot paths)

```mermaid
sequenceDiagram
    autonumber
    participant W as Embed widget
    participant E as EmbedController
    participant RT as AgentRuntime
    participant F as FlowExecutor
    participant L as LlmRouter
    participant T as Tools
    participant R as ConversationRecorder
    participant B as Broadcast (team channel)

    W->>E: POST message (visitor_token)
    E->>RT: run(agent, session, input)
    RT->>F: advance(state)
    F->>L: complete(prompt + tools)
    L-->>F: assistant turn (+ tool calls)
    F->>T: capture_lead / query_kb / end_session
    T->>R: persist transcript + lead
    R->>B: LeadSaved / LeadMessage
    B-->>W: live update (and dashboard browsers)
    RT-->>E: reply
    E-->>W: rendered response
```

## 3. State machine map

```mermaid
stateDiagram-v2
    direction LR
    [*] --> New
    New --> Qualified
    New --> Assigned
    New --> Won
    New --> Lost
    Qualified --> Assigned
    Qualified --> Won
    Qualified --> Lost
    Qualified --> New: demote (undo drag)
    Assigned --> Won
    Assigned --> Lost
    Assigned --> Qualified: demote (undo drag)
    Lost --> New: reopen
    Won --> [*]: terminal
```

Agent: `draft → active` (gated on health) · `active ⇄ disabled` ·
re-enable from disabled always allowed (native agents carry no credentials).
Conversation: `active → ended` only (terminal).

---

_The interactive `/architecture` page is regenerated live from the codebase on
every load — no manual step. These curated Mermaid blocks are hand-maintained;
refresh them when a new top-level subsystem (controller group, runtime stage, or
broadcast channel) lands. Last revised 2026-06-21._
```
