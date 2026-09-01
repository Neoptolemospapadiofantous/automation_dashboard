@php
    $title = ($config['title'] ?? '') !== '' ? $config['title'] : $agentName;
    $subtitle = $config['subtitle'] ?? 'AI assistant';
    $accent = $config['accent_color'] ?? '#000000';
    $onAccent = $config['text_color'] ?? '#FFFFFF';
    $avatar = $config['avatar_url'] ?? '';
    $showBranding = (bool) ($config['show_branding'] ?? true);
    $welcome = $config['welcome_message'] ?? '';
    $starters = $config['starter_prompts'] ?? [];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $agentName }} · Chat</title>
    <style>
        /* Flowstack brand — white sheet. Values inlined from
           resources/css/tokens.css (.sheet-white): this page is served into
           customers' iframes and deliberately loads no app bundle or
           external fonts. Hard edges (radius 0) per the brand. The accent is
           operator-configurable (Install page). */
        :root {
            --bg:          #FFFFFF;
            --bg-elev:     #FAFAFA;
            --surface:     #FAFAFA;
            --surface-hi:  #F0F0F0;
            --border-line: #E5E5E5;
            --border-hi:   #D4D4D4;
            --ink:         #000000;
            --ink-dim:     #525252;
            --ink-mute:    #8A8A8A;
            --accent:      {{ $accent }};
            --on-accent:   {{ $onAccent }};
            --font-mono: ui-monospace, "JetBrains Mono", "SFMono-Regular", Menlo, monospace;
        }
        * { box-sizing: border-box; }
        /* The page is a fixed app-shell: it must NEVER scroll itself (no x or
           y scrollbars on any device) — the thread and home lists are the only
           scroll containers. */
        html, body { margin: 0; height: 100%; overflow: hidden; }
        body {
            font-family: ui-sans-serif, system-ui, sans-serif;
            background: var(--bg);
            color: var(--ink);
            display: flex;
            flex-direction: column;
            height: 100%;
            /* Dynamic viewport height tracks the mobile URL bar / keyboard so the
               composer never hides behind browser chrome on the standalone page. */
            height: 100dvh;
            position: relative; /* anchors the rating modal overlay */
            /* Notched phones (widget goes fullscreen ≤480px): keep content clear
               of the rounded corners / camera cutout in landscape. viewport-fit=cover
               above opts us into the safe-area env() values; they resolve to 0 on
               desktop, so these are no-ops off-device. */
            padding-left: env(safe-area-inset-left);
            padding-right: env(safe-area-inset-right);
        }
        header {
            padding: calc(12px + env(safe-area-inset-top)) 16px 10px;
            border-bottom: 1px solid var(--border-line);
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
            background: var(--bg-elev);
        }
        /* Single line + ellipsis: on narrow phones the buttons must never
           push the title into a tall multi-line wrap. */
        header h1 {
            font-size: 14px; font-weight: 600; margin: 0;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        /* Direct header child spanning a full row of its own — beside the
           buttons it collapses into a sliver on phones and balloons the
           header to half the screen. */
        header .ai-disclosure {
            flex-basis: 100%; margin: 0;
            font-size: 10px; color: var(--ink-dim);
            font-family: var(--font-mono); letter-spacing: 0.04em;
        }
        header .head-text { flex: 1; min-width: 0; }
        header .subtitle {
            font-size: 11px; color: var(--ink-dim); margin: 1px 0 0;
            display: flex; align-items: center; gap: 6px; min-width: 0;
        }
        header .subtitle span + span {
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        header .status-dot {
            width: 7px; height: 7px; border-radius: 50%;
            background: #16a34a; flex: none;
            box-shadow: 0 0 0 0 rgba(22,163,74,.5);
            animation: fs-pulse 2s infinite;
        }
        @keyframes fs-pulse {
            0%   { box-shadow: 0 0 0 0 rgba(22,163,74,.45); }
            70%  { box-shadow: 0 0 0 5px rgba(22,163,74,0); }
            100% { box-shadow: 0 0 0 0 rgba(22,163,74,0); }
        }
        header .badge {
            width: 32px; height: 32px; border-radius: 0; overflow: hidden;
            background: var(--accent); color: var(--on-accent);
            display: flex; align-items: center; justify-content: center;
            font-size: 14px; font-weight: 700; flex: none;
            font-family: var(--font-mono);
        }
        header .badge img { width: 100%; height: 100%; object-fit: cover; display: block; }
        header .close-btn {
            flex: none;
            width: 30px; height: 30px; padding: 0;
            border: 1px solid transparent; border-radius: 0;
            background: transparent; color: var(--ink-dim);
            cursor: pointer; display: flex; align-items: center; justify-content: center;
            transition: background .15s, color .15s, border-color .15s;
        }
        header .close-btn:hover { background: var(--surface-hi); color: var(--ink); border-color: var(--border-line); }
        header .close-btn svg { width: 16px; height: 16px; display: block; }
        #thread {
            flex: 1; overflow-y: auto; overflow-x: hidden;
            /* Don't chain scroll to the host page (widget iframe on mobile). */
            overscroll-behavior: contain;
            padding: 16px; display: flex; flex-direction: column; gap: 10px;
            scroll-behavior: smooth;
        }
        /* A turn = optional avatar + bubble stack (bubble, sources, meta). */
        .turn { display: flex; gap: 8px; max-width: 88%; align-items: flex-end; }
        .turn.user { align-self: flex-end; flex-direction: row-reverse; }
        .turn.agent { align-self: flex-start; }
        .turn .turn-avatar {
            width: 26px; height: 26px; flex: none; border-radius: 0; overflow: hidden;
            background: var(--accent); color: var(--on-accent);
            display: flex; align-items: center; justify-content: center;
            font-size: 11px; font-weight: 700; font-family: var(--font-mono);
        }
        .turn .turn-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .turn .stack { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
        .turn.user .stack { align-items: flex-end; }
        .turn.agent .stack { align-items: flex-start; }
        .msg {
            max-width: 100%; padding: 10px 12px;
            border-radius: 0; font-size: 14px; line-height: 1.45;
            white-space: pre-wrap; word-wrap: break-word; overflow-wrap: anywhere;
        }
        .msg.user {
            background: var(--accent); color: var(--on-accent);
        }
        .msg.agent {
            background: var(--surface); color: var(--ink);
            border: 1px solid var(--border-line);
        }
        /* Safe markdown rendered inside agent bubbles. */
        .msg.agent a { color: inherit; text-decoration: underline; text-underline-offset: 2px; }
        .msg.agent strong { font-weight: 700; }
        .msg.agent em { font-style: italic; }
        .msg.agent ul { margin: 6px 0; padding-left: 18px; }
        .msg.agent li { margin: 2px 0; }
        .msg.agent p { margin: 0; }
        .msg.agent p + p { margin-top: 6px; }
        .msg.system {
            align-self: center;
            background: transparent; color: var(--ink-dim);
            font-size: 11px; font-family: var(--font-mono);
            max-width: 88%; text-align: center;
        }
        .meta {
            font-size: 10px; color: var(--ink-mute);
            font-family: var(--font-mono); letter-spacing: 0.03em;
            padding: 0 2px;
        }
        /* Empty/welcome intro line. */
        .welcome {
            align-self: flex-start; max-width: 88%;
            color: var(--ink-dim); font-size: 13px; line-height: 1.45;
            border-left: 2px solid var(--accent); padding: 2px 0 2px 10px;
        }
        form {
            display: flex; padding: 12px;
            border-top: 1px solid var(--border-line);
            gap: 8px; background: var(--bg); align-items: stretch;
        }
        /* When the branding footer is hidden the composer is the bottom-most
           element, so it must carry the home-indicator inset itself. */
        body.fs-nobrand form { padding-bottom: calc(12px + env(safe-area-inset-bottom)); }
        form input {
            /* min-width:0 lets the input shrink on narrow phones — its
               intrinsic size otherwise pushes the send button offscreen. */
            flex: 1; min-width: 0; padding: 10px 12px;
            /* 16px is the floor that stops iOS Safari zooming the page on focus. */
            border: 1px solid var(--border-hi); border-radius: 0;
            font-size: 16px; outline: none;
            background: var(--bg); color: var(--ink);
        }
        form input:focus { border-color: var(--accent); box-shadow: 0 0 0 2px var(--accent); }
        form button {
            width: 42px; flex: none; padding: 0;
            border: 1px solid var(--accent); border-radius: 0;
            background: var(--accent); color: var(--on-accent);
            cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: background .15s, color .15s, opacity .15s;
        }
        form button svg { width: 18px; height: 18px; display: block; }
        form button:hover:not(:disabled),
        form button:active:not(:disabled) { background: var(--bg); color: var(--accent); }
        form button:disabled { opacity: .5; cursor: not-allowed; }
        /* KB source chips — secondary metadata beneath an agent turn.
           Aligned to the agent bubble (flex-start), muted mono to read as
           provenance rather than message content. */
        .sources {
            display: flex; flex-wrap: wrap; gap: 4px; max-width: 100%;
        }
        .sources .source {
            border: 1px solid var(--border-line); border-radius: 0;
            background: var(--bg); color: var(--ink-dim);
            padding: 2px 6px; font-size: 10px;
            font-family: var(--font-mono); letter-spacing: 0.02em;
            max-width: 100%; overflow-wrap: anywhere;
        }
        .sources .source.more { border: 0; opacity: .6; padding: 2px 2px; }
        /* Quick-reply / starter-prompt chips and trace buttons. */
        .quick {
            align-self: flex-start;
            display: flex; flex-wrap: wrap; gap: 6px;
            max-width: 88%; margin-top: 2px;
        }
        .quick button {
            border: 1px solid var(--accent); border-radius: 0;
            background: var(--bg); color: var(--accent);
            padding: 7px 10px; font-size: 12px; cursor: pointer;
            font-family: var(--font-mono); letter-spacing: 0.02em;
            text-align: left; line-height: 1.3;
            max-width: 100%; overflow-wrap: anywhere;
            transition: background .15s, color .15s;
        }
        .quick button:hover, .quick button:active { background: var(--accent); color: var(--on-accent); }
        /* 3-dot bouncing thinking loader. */
        .typing {
            align-self: flex-start;
            background: var(--surface); color: var(--ink-dim);
            border: 1px solid var(--border-line);
            padding: 12px 14px; border-radius: 0;
            display: flex; gap: 5px; align-items: center;
        }
        .typing .dot {
            width: 6px; height: 6px; border-radius: 50%;
            background: var(--ink-mute); display: inline-block;
            animation: fs-bounce 1.2s infinite ease-in-out both;
        }
        .typing .dot:nth-child(1) { animation-delay: -0.24s; }
        .typing .dot:nth-child(2) { animation-delay: -0.12s; }
        .typing .dot:nth-child(3) { animation-delay: 0s; }
        @keyframes fs-bounce {
            0%, 80%, 100% { transform: scale(.6); opacity: .4; }
            40%           { transform: scale(1);  opacity: 1; }
        }
        .powered {
            text-align: center; padding: 6px 12px calc(6px + env(safe-area-inset-bottom));
            color: var(--ink-mute); font-size: 10px;
            font-family: var(--font-mono); letter-spacing: 0.06em;
            border-top: 1px solid var(--border-line); background: var(--bg-elev);
        }
        .powered a { color: var(--ink-dim); text-decoration: underline; text-underline-offset: 2px; }
        /* Header secondary action (End chat). */
        header .end-btn {
            flex: none; margin-left: auto; height: 30px; padding: 0 10px;
            border: 1px solid var(--border-line); border-radius: 0;
            background: transparent; color: var(--ink-dim);
            cursor: pointer; font-size: 11px; font-family: var(--font-mono);
            letter-spacing: 0.03em;
            transition: background .15s, color .15s, border-color .15s;
        }
        header .end-btn:hover { background: var(--surface-hi); color: var(--ink); border-color: var(--border-hi); }
        /* Post-chat rating modal — overlays the thread, not the whole page. */
        .rating-backdrop {
            position: absolute; inset: 0; z-index: 20;
            background: rgba(0, 0, 0, .35);
            display: flex; align-items: center; justify-content: center;
            padding: 16px;
        }
        .rating-card {
            background: var(--bg); border: 1px solid var(--border-hi);
            box-shadow: 0 8px 30px rgba(0, 0, 0, .18);
            width: 100%; max-width: 320px; padding: 18px;
            display: flex; flex-direction: column; gap: 12px;
        }
        .rating-card h2 { font-size: 14px; font-weight: 600; margin: 0; }
        .rating-card .opts { display: flex; gap: 8px; }
        .rating-card .opt {
            flex: 1; padding: 10px 4px; border: 1px solid var(--border-hi);
            border-radius: 0; background: var(--bg); color: var(--ink-dim);
            cursor: pointer; font-size: 12px; font-family: var(--font-mono);
            display: flex; flex-direction: column; align-items: center; gap: 4px;
            transition: background .15s, color .15s, border-color .15s;
        }
        .rating-card .opt .emoji { font-size: 20px; line-height: 1; }
        .rating-card .opt:hover { border-color: var(--ink); color: var(--ink); }
        .rating-card .opt.selected { background: var(--accent); color: var(--on-accent); border-color: var(--accent); }
        .rating-card textarea {
            width: 100%; min-height: 54px; resize: vertical;
            border: 1px solid var(--border-hi); border-radius: 0;
            /* 16px: same iOS no-zoom floor as the composer input. */
            padding: 8px 10px; font-size: 16px; font-family: inherit;
            background: var(--bg); color: var(--ink); outline: none;
        }
        .rating-card textarea:focus { border-color: var(--accent); box-shadow: 0 0 0 2px var(--accent); }
        .rating-card .actions { display: flex; gap: 8px; justify-content: flex-end; }
        .rating-card .actions button {
            padding: 8px 14px; border-radius: 0; cursor: pointer;
            font-size: 12px; font-family: var(--font-mono); letter-spacing: 0.03em;
            transition: background .15s, color .15s, opacity .15s;
        }
        .rating-card .skip {
            border: 1px solid transparent; background: transparent; color: var(--ink-mute);
        }
        .rating-card .skip:hover { color: var(--ink); }
        .rating-card .submit {
            border: 1px solid var(--accent); background: var(--accent); color: var(--on-accent);
        }
        .rating-card .submit:hover:not(:disabled) { background: var(--bg); color: var(--accent); }
        .rating-card .submit:disabled { opacity: .45; cursor: not-allowed; }
        /* Home / landing view — shown on open (no active chat) and after a
           reset. A clean start point: welcome + "new chat" + recent chats. */
        #home {
            flex: 1; overflow-y: auto; overflow-x: hidden;
            overscroll-behavior: contain;
            padding: 16px;
            display: flex; flex-direction: column; gap: 14px;
        }
        .home-intro {
            color: var(--ink-dim); font-size: 14px; line-height: 1.5;
            border-left: 2px solid var(--accent); padding: 4px 0 4px 12px;
        }
        .newchat-btn {
            border: 1px solid var(--accent); border-radius: 0;
            background: var(--accent); color: var(--on-accent);
            padding: 12px 14px; font-size: 13px; cursor: pointer;
            font-family: var(--font-mono); letter-spacing: 0.03em;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            transition: background .15s, color .15s;
        }
        .newchat-btn:hover { background: var(--bg); color: var(--accent); }
        .history-head {
            font-size: 11px; color: var(--ink-mute); font-family: var(--font-mono);
            letter-spacing: 0.06em; text-transform: uppercase;
            border-top: 1px solid var(--border-line); padding-top: 12px;
        }
        .history { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
        .history-item {
            border: 1px solid var(--border-line); border-radius: 0;
            background: var(--bg); padding: 10px 12px; cursor: pointer;
            display: flex; flex-direction: column; gap: 4px; text-align: left; width: 100%;
            font-family: inherit; transition: background .15s, border-color .15s;
        }
        .history-item:hover { background: var(--surface-hi); border-color: var(--border-hi); }
        .history-item .hi-top { display: flex; align-items: center; gap: 8px; }
        .history-item .hi-title {
            flex: 1; min-width: 0; font-size: 13px; color: var(--ink);
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .history-item .hi-when { font-size: 10px; color: var(--ink-mute); font-family: var(--font-mono); flex: none; }
        .hi-badge {
            font-size: 10px; font-family: var(--font-mono); padding: 1px 6px; flex: none;
            border: 1px solid var(--border-line); color: var(--ink-dim);
        }
        .hi-badge.good { background: #dcfce7; color: #15803d; border-color: #bbf7d0; }
        .hi-badge.ok   { background: #fef3c7; color: #b45309; border-color: #fde68a; }
        .hi-badge.bad  { background: #ffe4e6; color: #be123c; border-color: #fecdd3; }
        /* Read-only transcript back bar (shown when reopening a past chat). */
        .backbar {
            display: flex; align-items: center; justify-content: space-between; gap: 8px;
            padding: 8px 12px; border-bottom: 1px solid var(--border-line); background: var(--bg-elev);
        }
        .backbar button {
            border: 1px solid var(--border-line); border-radius: 0; background: transparent;
            color: var(--ink-dim); cursor: pointer; padding: 6px 10px;
            font-size: 11px; font-family: var(--font-mono); letter-spacing: 0.03em;
            transition: background .15s, color .15s, border-color .15s;
        }
        .backbar button:hover { background: var(--surface-hi); color: var(--ink); border-color: var(--border-hi); }
        .backbar .back-new { border-color: var(--accent); color: var(--accent); }
        .backbar .back-new:hover { background: var(--accent); color: var(--on-accent); }
        /* hidden must win over the flex/block display rules above. */
        #home[hidden], .backbar[hidden], #thread[hidden], form[hidden] { display: none; }
        /* Clean surfaces: no visible scrollbar tracks anywhere — the thread
           and home lists scroll by touch/wheel/keys only. */
        #thread, #home { scrollbar-width: none; }
        #thread::-webkit-scrollbar, #home::-webkit-scrollbar { display: none; }
        /* Very narrow phones (Galaxy Fold cover display etc.): tighter gutters
           so bubbles and chips keep usable width. */
        @media (max-width: 359px) {
            header { padding-left: 12px; padding-right: 12px; }
            #thread, #home { padding: 12px; }
            form { padding: 10px; }
        }
        /* Hosted chat page in a desktop browser: keep the conversation a
           readable column instead of running bubbles and the composer edge to
           edge across the window. The header/composer bars stay full-bleed,
           their content sits in the column. Never fires inside the widget —
           its iframe is at most 408px wide. */
        @media (min-width: 768px) {
            header, .backbar, #thread, #home, form, .powered {
                padding-left: max(16px, calc((100% - 760px) / 2));
                padding-right: max(16px, calc((100% - 760px) / 2));
            }
        }
        @media (prefers-reduced-motion: reduce) {
            #thread { scroll-behavior: auto; }
            .typing .dot, header .status-dot { animation: none; }
        }
    </style>
</head>
<body class="{{ $showBranding ? '' : 'fs-nobrand' }}">
<header>
    <div class="badge">
        @if ($avatar !== '')
            <img src="{{ $avatar }}" alt="">
        @else
            {{ strtoupper(substr($title, 0, 1)) }}
        @endif
    </div>
    <div class="head-text">
        <h1>{{ $title }}</h1>
        @if ($subtitle !== '')
            <p class="subtitle"><span class="status-dot" aria-hidden="true"></span><span>{{ $subtitle }}</span></p>
        @endif
    </div>
    <button type="button" id="fs-end" class="end-btn" aria-label="End chat and leave feedback" hidden>End chat</button>
    <button type="button" id="fs-close" class="close-btn" aria-label="Close chat">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
    </button>
    {{-- EU AI Act Art. 50 transparency: rendered by the PLATFORM,
         independent of agent scripting, at every conversation. A direct
         header child so it wraps to its own full-width row — nested beside
         the buttons it collapses into a sliver on phones. --}}
    <p class="ai-disclosure">AI assistant — not a person. You can ask for a human at any time.</p>
</header>

<div id="backbar" class="backbar" hidden>
    <button type="button" id="fs-back" class="back-btn">← Back</button>
    <button type="button" id="fs-newchat-2" class="back-new">Start new chat</button>
</div>

<div id="thread" role="log" aria-live="polite"></div>

<div id="home" hidden>
    <p class="home-intro" id="home-intro" hidden></p>
    <button type="button" id="fs-newchat" class="newchat-btn">Start new chat</button>
    <div class="history-head" id="history-head" hidden>Recent conversations</div>
    <ul id="history" class="history"></ul>
</div>

<form id="composer" autocomplete="off">
    {{-- No `autofocus` attribute: Chrome logs a "Blocked autofocusing"
         console error for it in cross-origin iframes (every embedding
         site sees it on load). Focus is handled in JS instead — on init
         and on the widget's fs:visible message — which covers the
         standalone hosted page too. --}}
    <input id="msg" type="text" placeholder="Type a message…" required maxlength="2000">
    <button type="submit" id="send" aria-label="Send">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.27 3.13a.6.6 0 0 1 .82-.73l16.5 8.06a.6.6 0 0 1 0 1.08l-16.5 8.06a.6.6 0 0 1-.82-.73L6 12zm0 0h7"/></svg>
    </button>
</form>

@if ($showBranding)
    <div class="powered">Powered by <a href="https://flowstack.run" target="_blank" rel="noopener">Flowstack</a></div>
@endif

<script>
(function () {
    var slug = {!! json_encode($slug) !!};
    var HOST = {!! json_encode($host) !!}; // parent page host, forwarded by the loader (?ref=)
    var AVATAR = {!! json_encode($avatar) !!};
    var TITLE = {!! json_encode($title) !!};
    var WELCOME = {!! json_encode($welcome) !!};
    var STARTERS = {!! json_encode(array_values((array) $starters)) !!};
    // Closing an idle chat (config/runtime.php auto_close). The ASK is here,
    // client-side and static — no model call, no credits — because only an open
    // panel can be asked. The CLOSE is also enforced server-side by
    // conversations:auto-close, which is what catches the visitor who simply
    // closed the tab; no browser timer survives that.
    var IDLE_NUDGE_MS = {!! json_encode(((int) config('runtime.auto_close.nudge_after_minutes', 30)) * 60000) !!};
    var IDLE_CLOSE_MS = {!! json_encode(((int) config('runtime.auto_close.close_after_minutes', 120)) * 60000) !!};
    var IDLE_NUDGE_TEXT = 'Anything else I can help with? I\'ll close this chat shortly — just reply and I\'ll pick it back up.';
    // TOKEN_KEY = stable browser identity (survives reset → groups a visitor's
    // chats for the home screen). SESSION_KEY = the current chat session id
    // (one runtime session + transcript row per chat; cleared on reset).
    var TOKEN_KEY = 'fs_visitor_' + slug;
    var SESSION_KEY = 'fs_session_' + slug;
    var thread = document.getElementById('thread');
    var home = document.getElementById('home');
    var backbar = document.getElementById('backbar');
    var form = document.getElementById('composer');
    var input = document.getElementById('msg');
    var sendBtn = document.getElementById('send');
    var closeBtn = document.getElementById('fs-close');
    var endBtn = document.getElementById('fs-end');
    var newChatBtn = document.getElementById('fs-newchat');
    var newChatBtn2 = document.getElementById('fs-newchat-2');
    var backBtn = document.getElementById('fs-back');
    var rated = false; // guards the post-chat rating prompt to once per conversation
    var lastActivityAt = Date.now(); // idle clock: last message either side
    var idleNudged = false;          // the "anything else?" line fires once per lull
    var idleTimer = null;
    var takeoverLive = false;        // a teammate is in the chat — never nudge or close
    var visitorId = null; // current chat session id

    // Auto-start for first-time visitors: someone who OPENS the widget should
    // meet the greeting, not a lone "Start new chat" button. Guards, in order:
    // the loader mounts this iframe eagerly on EVERY pageview, so we only fire
    // once the panel is actually visible (fs:visible / standalone page); only
    // when the history request CONFIRMED zero past conversations (returning
    // visitors keep their home screen; unknown/failed = no auto-start); and at
    // most once per page load.
    var widgetVisible = window.self === window.top; // hosted chat page = visible at load
    var hasHistory = null; // null = not known yet
    var autoStarted = false;
    function maybeAutoStart() {
        if (autoStarted || home.hidden || visitorId || !widgetVisible || hasHistory !== false) return;
        autoStarted = true;
        startNewChat();
    }
    var token = null;     // stable visitor token
    var pendingSend = null; // a host-API message to send once a new chat is live
    var csrf = document.querySelector('meta[name="csrf-token"]').content;
    var agentInitial = (TITLE || '?').trim().charAt(0).toUpperCase() || '?';

    // Tell the host page (loader) about lifecycle + new messages. The loader
    // origin-checks these; we post to the parent only.
    function toParent(msg) {
        try { if (window.parent && window.parent !== window) window.parent.postMessage(msg, '*'); } catch (e) {}
    }

    function scrollToBottom() {
        // rAF so layout has settled before we measure scrollHeight.
        requestAnimationFrame(function () { thread.scrollTop = thread.scrollHeight; });
    }

    function timeNow() {
        try {
            return new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        } catch (e) { return ''; }
    }

    // --- localStorage identity helpers ---
    function lsGet(k) { try { return window.localStorage.getItem(k) || null; } catch (e) { return null; } }
    function lsSet(k, v) { if (v) { try { window.localStorage.setItem(k, v); } catch (e) {} } }
    function lsDel(k) { try { window.localStorage.removeItem(k); } catch (e) {} }

    // Mint an "embed-"+28-char id (same shape the backend validates/mints).
    function mintId() {
        var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        var out = '';
        var rand = new Uint8Array(28);
        (window.crypto || window.msCrypto).getRandomValues(rand);
        for (var i = 0; i < 28; i++) { out += chars[rand[i] % chars.length]; }
        return 'embed-' + out;
    }

    // The stable token must exist before any chat so history can group by it.
    function ensureToken() {
        token = lsGet(TOKEN_KEY);
        if (!/^embed-[A-Za-z0-9]{16,48}$/.test(token || '')) {
            token = mintId();
            lsSet(TOKEN_KEY, token);
        }
        return token;
    }

    // --- view toggles: home (landing) / chat / read-only transcript ---
    function showHome() {
        home.hidden = false;
        thread.hidden = true;
        backbar.hidden = true;
        form.hidden = true;
        endBtn.hidden = true;
        loadHistory();
    }
    function showChatView() {
        home.hidden = true;
        backbar.hidden = true;
        thread.hidden = false;
        form.hidden = false;
        endBtn.hidden = false;
    }
    function showTranscriptView() {
        home.hidden = true;
        thread.hidden = false;
        backbar.hidden = false;
        form.hidden = true;
        endBtn.hidden = true;
    }

    // --- safe markdown for AGENT message text only ---
    // CRITICAL: escape HTML first (no raw injection), THEN apply transforms.
    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function safeUrl(url) {
        var u = String(url || '').trim();
        // Only allow http(s) and mailto; otherwise neutralise.
        if (/^https?:\/\//i.test(u) || /^mailto:/i.test(u)) return u;
        return '';
    }
    function renderMarkdown(raw) {
        var html = escapeHtml(raw);

        // Inline links: [text](url) — text and url are already escaped.
        html = html.replace(/\[([^\]]+)\]\((&[^;\s]+;|[^)\s]+)\)/g, function (m, text, url) {
            // Unescape entities we created so safeUrl sees the real scheme.
            var rawUrl = url.replace(/&amp;/g, '&').replace(/&#39;/g, "'").replace(/&quot;/g, '"');
            var safe = safeUrl(rawUrl);
            if (!safe) return text;
            return '<a href="' + escapeHtml(safe) + '" target="_blank" rel="noopener noreferrer">' + text + '</a>';
        });

        // Bare URLs (not already inside an href="...").
        html = html.replace(/(^|[\s(])((?:https?:\/\/)[^\s<)]+)/g, function (m, pre, url) {
            var trail = '';
            var clean = url.replace(/[.,!?;:]+$/, function (t) { trail = t; return ''; });
            var rawUrl = clean.replace(/&amp;/g, '&').replace(/&#39;/g, "'").replace(/&quot;/g, '"');
            var safe = safeUrl(rawUrl);
            if (!safe) return m;
            return pre + '<a href="' + escapeHtml(safe) + '" target="_blank" rel="noopener noreferrer">' + clean + '</a>' + trail;
        });

        // Bold then italic (bold first so ** isn't eaten by single-*).
        html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        html = html.replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>');

        // "- " bullet lists: group consecutive bullet lines into a <ul>.
        var lines = html.split('\n');
        var out = [];
        var inList = false;
        for (var i = 0; i < lines.length; i++) {
            var m = lines[i].match(/^\s*-\s+(.*)$/);
            if (m) {
                if (!inList) { out.push('<ul>'); inList = true; }
                out.push('<li>' + m[1] + '</li>');
            } else {
                if (inList) { out.push('</ul>'); inList = false; }
                out.push(lines[i]);
            }
        }
        if (inList) out.push('</ul>');
        html = out.join('\n');

        // Remaining newlines -> <br>, but not the structural ones around <ul>/<li>.
        html = html
            .replace(/\n?(<\/?(?:ul|li)>)\n?/g, '$1')
            .replace(/\n/g, '<br>');

        return html;
    }

    // --- message rendering ---
    // Build a turn: avatar (agent only) + a stack holding the bubble plus any
    // sources/meta. Returns the stack so callers can append sources to it.
    function addMsg(role, text, opts) {
        opts = opts || {};
        var turn = document.createElement('div');
        turn.className = 'turn ' + role;

        if (role === 'agent') {
            var av = document.createElement('div');
            av.className = 'turn-avatar';
            if (AVATAR) {
                var img = document.createElement('img');
                img.src = AVATAR; img.alt = '';
                av.appendChild(img);
            } else {
                av.textContent = agentInitial;
            }
            turn.appendChild(av);
        }

        var stack = document.createElement('div');
        stack.className = 'stack';

        var bubble = document.createElement('div');
        bubble.className = 'msg ' + role;
        if (role === 'agent') {
            bubble.innerHTML = renderMarkdown(text); // escaped inside renderMarkdown
        } else {
            bubble.textContent = text; // user stays plain text
        }
        stack.appendChild(bubble);

        var meta = document.createElement('div');
        meta.className = 'meta';
        meta.textContent = opts.time || timeNow();
        stack.appendChild(meta);

        turn.appendChild(stack);
        thread.appendChild(turn);
        scrollToBottom();
        return stack;
    }

    function addSystem(text) {
        var d = document.createElement('div');
        d.className = 'msg system';
        d.textContent = text;
        thread.appendChild(d);
        scrollToBottom();
        return d;
    }

    // Render deduped KB source chips beneath an agent turn. citations is the
    // raw trace payload array; may be null/undefined/empty (greetings,
    // low-confidence answers, no-KB replies) in which case nothing renders.
    // `stack` (optional) is the agent turn's stack so chips sit under the bubble.
    function addSources(citations, stack) {
        if (!Array.isArray(citations) || !citations.length) return;
        var seen = {};
        var titles = [];
        for (var i = 0; i < citations.length; i++) {
            var title = citations[i] && citations[i].document_title;
            if (!title || seen[title]) continue;
            seen[title] = true;
            titles.push(title);
        }
        if (!titles.length) return;
        var wrap = document.createElement('div');
        wrap.className = 'sources';
        titles.slice(0, 3).forEach(function (title) {
            var chip = document.createElement('span');
            chip.className = 'source';
            chip.textContent = 'Source: ' + title;
            wrap.appendChild(chip);
        });
        if (titles.length > 3) {
            var more = document.createElement('span');
            more.className = 'source more';
            more.textContent = '+' + (titles.length - 3) + ' more';
            wrap.appendChild(more);
        }
        (stack || thread).appendChild(wrap);
        scrollToBottom();
    }

    // Quick-reply chips (starter prompts / trace buttons). Each item is a
    // {label,value} object or a bare string. Tapping sends the value and
    // removes the whole chip group.
    function addQuickReplies(items) {
        var list = (items || []).map(function (it) {
            if (typeof it === 'string') return { label: it, value: it };
            if (it && typeof it === 'object') return { label: it.label || it.value || '', value: it.value || it.label || '' };
            return null;
        }).filter(function (it) { return it && it.value; });
        if (!list.length) return;
        var wrap = document.createElement('div');
        wrap.className = 'quick';
        list.forEach(function (it) {
            var b = document.createElement('button');
            b.type = 'button';
            b.textContent = it.label;
            b.addEventListener('click', function () {
                if (wrap.parentNode) wrap.parentNode.removeChild(wrap);
                send(it.value);
            });
            wrap.appendChild(b);
        });
        thread.appendChild(wrap);
        scrollToBottom();
    }

    function addTyping() {
        var d = document.createElement('div');
        d.className = 'typing';
        d.setAttribute('aria-label', 'Assistant is typing');
        d.innerHTML = '<span class="dot"></span><span class="dot"></span><span class="dot"></span>';
        thread.appendChild(d);
        scrollToBottom();
        return d;
    }

    function addWelcome(text) {
        if (!text) return;
        var d = document.createElement('div');
        d.className = 'welcome';
        d.textContent = text;
        thread.appendChild(d);
        scrollToBottom();
    }

    function renderTraces(traces) {
        if (!Array.isArray(traces)) return;
        traces.forEach(function (t) {
            if ((t.type === 'text' || t.type === 'speak') && t.payload && t.payload.message) {
                var stack = addMsg('agent', t.payload.message);
                addSources(t.payload.citations, stack);
                toParent({ type: 'fs:message', text: t.payload.message });
            }
            if (t.payload && Array.isArray(t.payload.buttons) && t.payload.buttons.length) {
                addQuickReplies(t.payload.buttons);
            }
        });
    }

    // Replay a resumed transcript. Roles are 'user' | 'agent'; no citations.
    function renderTranscript(transcript) {
        if (!Array.isArray(transcript)) return;
        transcript.forEach(function (m) {
            if (!m || !m.text) return;
            var role = m.role === 'user' ? 'user' : 'agent';
            var stack = addMsg(role, m.text);
            if (role === 'agent' && m.citations) addSources(m.citations, stack);
        });
    }

    function callJson(path, body) {
        body = body || {};
        if (HOST) body.host = HOST; // backend domain check for restricted agents
        return fetch(path, {
            method: 'POST',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'Accept': 'application/json',
            },
            body: JSON.stringify(body),
        }).then(function (r) {
            return r.json().then(function (j) {
                return { status: r.status, body: j };
            });
        });
    }

    // --- human takeover ---
    // When the conversation is escalated (handoff) or a teammate has taken
    // over, the widget polls for TEAM replies — those arrive from the
    // dashboard, not as responses to the visitor's own messages. AI replies
    // still come inline via /interact, so polling only for 'human'-role
    // messages can never duplicate anything.
    var humanSeq = 0;          // sequence cursor for delivered team replies
    var pollTimer = null;
    var teamJoinedShown = false;

    function handleHandoffState(body) {
        if (!body) return;
        if (typeof body.last_seq === 'number' && body.last_seq > humanSeq) humanSeq = body.last_seq;
        if (body.takeover && !teamJoinedShown) {
            teamJoinedShown = true;
            addSystem('A teammate has joined the conversation.');
        }
        takeoverLive = !!body.takeover;
        if ((body.handoff || body.takeover) && !pollTimer) startPolling();
        if (body.ended) { stopPolling(); stopIdleWatch(); }
    }

    // --- idle close -----------------------------------------------------------
    // Two stages, matching the server's: ask, then close. Any message from
    // either side resets the clock, and a live teammate suspends it entirely.
    function markActivity() {
        lastActivityAt = Date.now();
        idleNudged = false;
    }

    function startIdleWatch() {
        if (idleTimer) return;
        idleTimer = setInterval(function () {
            if (rated || !visitorId || takeoverLive) return;
            var idle = Date.now() - lastActivityAt;
            if (!idleNudged && idle >= IDLE_NUDGE_MS) {
                idleNudged = true;
                addMsg('agent', IDLE_NUDGE_TEXT);
                return;
            }
            if (idle >= IDLE_CLOSE_MS) {
                stopIdleWatch();
                showRating(true);
            }
        }, 30000);
    }

    function stopIdleWatch() {
        if (idleTimer) { clearInterval(idleTimer); idleTimer = null; }
    }

    function startPolling() {
        if (pollTimer) return;
        pollTimer = setInterval(pollTeamReplies, 4000);
    }

    function stopPolling() {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    }

    async function pollTeamReplies() {
        if (!visitorId) return;
        try {
            var r = await callJson('/embed/' + encodeURIComponent(slug) + '/poll',
                { visitor_id: visitorId, after: humanSeq });
            if (r.status !== 200) return;
            var msgs = Array.isArray(r.body.messages) ? r.body.messages : [];
            msgs.forEach(function (m) {
                if (m.sequence > humanSeq) humanSeq = m.sequence;
                if (!teamJoinedShown) {
                    teamJoinedShown = true;
                    addSystem('A teammate has joined the conversation.');
                }
                addMsg('agent', m.text);
                markActivity();
            });
            if (r.body.ended) { stopPolling(); return; }
            if (!r.body.handoff && !r.body.takeover) stopPolling();
        } catch (e) { /* transient — next tick retries */ }
    }

    // Start (or resume) a chat session. `sessionId` keys the runtime session +
    // transcript row; `token` is sent so the conversation is grouped under the
    // stable visitor identity for the home screen.
    async function launch(sessionId) {
        showChatView();
        thread.innerHTML = '';
        rated = false;
        input.disabled = false;
        sendBtn.disabled = false;
        try {
            var r = await callJson('/embed/' + encodeURIComponent(slug) + '/launch',
                { visitor_id: sessionId, visitor_token: token });
            if (r.status !== 200) {
                addSystem(r.body.error || 'Could not start the chat.');
                sendBtn.disabled = true;
                return;
            }
            visitorId = r.body.visitor_id;
            lsSet(SESSION_KEY, visitorId);
            markActivity();
            startIdleWatch();

            var resumed = r.body.resumed && Array.isArray(r.body.transcript) && r.body.transcript.length;
            if (resumed) {
                // Returning to a live session: replay history, skip greeting.
                renderTranscript(r.body.transcript);
            } else {
                // New thread: optional welcome, the greeting traces, then the
                // quick-reply chips — configured starters plus the FAQ
                // categories (tapping one is answered for free, no AI call).
                addWelcome(WELCOME);
                renderTraces(r.body.traces);
                var chips = Array.isArray(r.body.chips) ? r.body.chips : [];
                addQuickReplies(STARTERS.concat(chips));
            }
            handleHandoffState(r.body);
            input.focus();
            toParent({ type: 'fs:ready' });
            if (pendingSend) { var t = pendingSend; pendingSend = null; send(t); }
        } catch (e) {
            addSystem('Connection failed. Please refresh.');
        }
    }

    // Begin a brand-new chat: mint a fresh session id (so the engine greets
    // rather than resuming) under the same stable visitor token. An optional
    // firstText is sent once the session is live (host JS API from home).
    function startNewChat(firstText) {
        pendingSend = typeof firstText === 'string' ? firstText : null;
        var sid = mintId();
        lsSet(SESSION_KEY, sid);
        launch(sid);
    }

    var RATING_LABEL = { good: '☺ Good', ok: '😐 OK', bad: '☹ Bad' };

    function formatWhen(iso) {
        if (!iso) return '';
        try {
            var d = new Date(iso);
            var today = new Date();
            var sameDay = d.toDateString() === today.toDateString();
            return sameDay
                ? d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
                : d.toLocaleDateString([], { month: 'short', day: 'numeric' });
        } catch (e) { return ''; }
    }

    // Fetch + render the visitor's last 5 conversations on the home screen.
    function loadHistory() {
        var intro = document.getElementById('home-intro');
        intro.textContent = WELCOME || '';
        intro.hidden = !WELCOME;
        var list = document.getElementById('history');
        var head = document.getElementById('history-head');
        list.innerHTML = '';
        head.hidden = true;
        if (!token) return;
        callJson('/embed/' + encodeURIComponent(slug) + '/history', { visitor_token: token })
            .then(function (r) {
                if (r.status !== 200 || !r.body || !Array.isArray(r.body.conversations) || !r.body.conversations.length) {
                    // A confirmed-empty history unlocks first-visit auto-start;
                    // a non-200 stays "unknown" (no auto-start, old behavior).
                    if (r.status === 200) { hasHistory = false; maybeAutoStart(); }

                    return;
                }
                hasHistory = true;
                head.hidden = false;
                r.body.conversations.forEach(function (c) { list.appendChild(historyItem(c)); });
            })
            .catch(function () { /* best-effort: home still shows "new chat" */ });
    }

    function historyItem(c) {
        var li = document.createElement('li');
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'history-item';

        var top = document.createElement('div');
        top.className = 'hi-top';

        var title = document.createElement('span');
        title.className = 'hi-title';
        title.textContent = c.title || 'Conversation';
        top.appendChild(title);

        if (c.rating && RATING_LABEL[c.rating]) {
            var badge = document.createElement('span');
            badge.className = 'hi-badge ' + c.rating;
            badge.textContent = RATING_LABEL[c.rating];
            top.appendChild(badge);
        }

        var when = document.createElement('span');
        when.className = 'hi-when';
        when.textContent = formatWhen(c.last_message_at);
        top.appendChild(when);

        btn.appendChild(top);
        btn.addEventListener('click', function () { openTranscript(c.id); });
        li.appendChild(btn);
        return li;
    }

    // Open a past conversation read-only (its runtime session may be gone).
    function openTranscript(id) {
        showTranscriptView();
        thread.innerHTML = '';
        callJson('/embed/' + encodeURIComponent(slug) + '/conversation',
            { visitor_token: token, conversation_id: id })
            .then(function (r) {
                if (r.status !== 200 || !r.body || !Array.isArray(r.body.messages)) {
                    addSystem('Could not load this conversation.');
                    return;
                }
                if (!r.body.messages.length) { addSystem('No messages in this conversation.'); return; }
                renderTranscript(r.body.messages);
            })
            .catch(function () { addSystem('Connection failed.'); });
    }

    async function send(text) {
        text = (text || '').trim();
        if (!text || !visitorId || sendBtn.disabled) return;
        // Tapping a chip should clear any other lingering chip groups.
        Array.prototype.slice.call(thread.querySelectorAll('.quick')).forEach(function (q) { q.remove(); });
        addMsg('user', text);
        markActivity();
        input.value = '';
        sendBtn.disabled = true;
        var typing = addTyping();
        try {
            var r = await callJson('/embed/' + encodeURIComponent(slug) + '/interact', {
                visitor_id: visitorId,
                visitor_token: token,
                message: text,
            });
            typing.remove();
            if (r.status !== 200) {
                addSystem(r.body.error || 'Could not deliver the message.');
            } else {
                renderTraces(r.body.traces);
                markActivity();
                handleHandoffState(r.body);
                // Runtime reached a terminal flow state → prompt for a rating,
                // then close the panel once they're done (autoEnded = true).
                if (r.body.ended) showRating(true);
            }
        } catch (err) {
            typing.remove();
            addSystem('Connection failed.');
        } finally {
            // Don't re-enable the composer once the rating prompt has taken over.
            if (!rated) {
                sendBtn.disabled = false;
                input.focus();
            }
        }
    }

    // --- post-chat rating ---
    // Two triggers: the runtime reaching a terminal flow state (send() passes
    // r.body.ended, autoEnded=true), or the visitor tapping "End chat"
    // (autoEnded=false). Both surface the same bad/ok/good + optional comment
    // prompt and reset to a fresh conversation; when the runtime auto-ended,
    // we also collapse the panel once the visitor is done rating.
    function showRating(autoEnded) {
        if (rated || document.getElementById('fs-rating')) return;
        rated = true;
        sendBtn.disabled = true;
        input.disabled = true;

        var backdrop = document.createElement('div');
        backdrop.className = 'rating-backdrop';
        backdrop.id = 'fs-rating';

        var card = document.createElement('div');
        card.className = 'rating-card';
        card.setAttribute('role', 'dialog');
        card.setAttribute('aria-modal', 'true');
        card.setAttribute('aria-label', 'Rate this conversation');

        var h = document.createElement('h2');
        h.textContent = 'How did this conversation go?';
        card.appendChild(h);

        var opts = document.createElement('div');
        opts.className = 'opts';
        var chosen = null;
        var submitBtn;
        [
            { value: 'bad',  emoji: '☹', label: 'Bad' },
            { value: 'ok',   emoji: '😐', label: 'OK' },
            { value: 'good', emoji: '☺', label: 'Good' }
        ].forEach(function (r) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'opt';
            b.setAttribute('aria-label', r.label);
            var em = document.createElement('span');
            em.className = 'emoji';
            em.textContent = r.emoji;
            b.appendChild(em);
            b.appendChild(document.createTextNode(r.label));
            b.addEventListener('click', function () {
                chosen = r.value;
                Array.prototype.slice.call(opts.children).forEach(function (c) { c.classList.remove('selected'); });
                b.classList.add('selected');
                if (submitBtn) submitBtn.disabled = false;
            });
            opts.appendChild(b);
        });
        card.appendChild(opts);

        var comment = document.createElement('textarea');
        comment.placeholder = 'Add a comment (optional)';
        comment.maxLength = 2000;
        card.appendChild(comment);

        var actions = document.createElement('div');
        actions.className = 'actions';

        var skipBtn = document.createElement('button');
        skipBtn.type = 'button';
        skipBtn.className = 'skip';
        skipBtn.textContent = 'Skip';
        skipBtn.addEventListener('click', function () {
            backdrop.remove();
            resetConversation();
            if (autoEnded) toParent({ type: 'fs:close' });
        });

        submitBtn = document.createElement('button');
        submitBtn.type = 'button';
        submitBtn.className = 'submit';
        submitBtn.textContent = 'Submit';
        submitBtn.disabled = true;
        submitBtn.addEventListener('click', function () {
            if (!chosen) return;
            submitBtn.disabled = true;
            submitFeedback(chosen, comment.value).then(function () {
                backdrop.remove();
                resetConversation();
                if (autoEnded) toParent({ type: 'fs:close' });
            });
        });

        actions.appendChild(skipBtn);
        actions.appendChild(submitBtn);
        card.appendChild(actions);

        backdrop.appendChild(card);
        document.body.appendChild(backdrop);
        toParent({ type: 'fs:rating' });
    }

    function submitFeedback(rating, comment) {
        if (!visitorId) return Promise.resolve();
        return callJson('/embed/' + encodeURIComponent(slug) + '/feedback', {
            visitor_id: visitorId,
            rating: rating,
            comment: comment || ''
        }).catch(function () { /* best-effort: still reset on a failed POST */ });
    }

    // End the chat: drop the session id (keep the stable token so history
    // persists), clear the thread, and return to the clean home/landing view.
    function resetConversation() {
        lsDel(SESSION_KEY);
        visitorId = null;
        rated = false;
        thread.innerHTML = '';
        input.disabled = false;
        input.value = '';
        sendBtn.disabled = false;
        toParent({ type: 'fs:reset' });
        showHome();
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        send(input.value);
    });

    // On-screen keyboard: browsers that only shrink the VISUAL viewport
    // (iOS Safari — no interactive-widget support) leave the layout viewport
    // tall, hiding the composer behind the keyboard. Pin the app shell to the
    // visual viewport while they differ. Chrome resizes the layout viewport
    // (meta interactive-widget=resizes-content), so this stays a no-op there;
    // inside the widget iframe the loader does the equivalent for the frame.
    if (window.visualViewport) {
        var vv = window.visualViewport;
        var syncKeyboard = function () {
            var overlay = window.innerHeight - vv.height;
            document.body.style.height = overlay > 1 ? vv.height + 'px' : '';
            scrollToBottom();
        };
        vv.addEventListener('resize', syncKeyboard);
        vv.addEventListener('scroll', syncKeyboard);
    }

    // Header close button -> loader closes the panel.
    closeBtn.addEventListener('click', function () {
        toParent({ type: 'fs:close' });
    });

    // "End chat" -> manual rating trigger (works even if the runtime hasn't
    // reached a terminal flow state).
    endBtn.addEventListener('click', function () {
        showRating();
    });

    // Home screen: start a fresh chat.
    newChatBtn.addEventListener('click', startNewChat);
    // Transcript view: back to home, or start a fresh chat.
    newChatBtn2.addEventListener('click', startNewChat);
    backBtn.addEventListener('click', showHome);

    // Host JS API bridge: the loader relays window.flowstack.sendMessage()
    // and open() here.
    window.addEventListener('message', function (e) {
        var d = e.data || {};
        if (d.type === 'fs:send' && typeof d.text === 'string') {
            // A host-driven message starts a chat if we're on the home screen.
            if (!visitorId) { startNewChat(d.text); } else { send(d.text); }
        } else if (d.type === 'fs:visible') {
            widgetVisible = true;
            if (!home.hidden) { maybeAutoStart(); } else { input.focus(); }
        }
    });

    // Boot: ensure the stable token exists, then resume an in-progress chat if
    // there is one, otherwise land on the clean home screen.
    ensureToken();
    var existingSession = lsGet(SESSION_KEY);
    if (/^embed-[A-Za-z0-9]{16,48}$/.test(existingSession || '')) {
        launch(existingSession);
    } else {
        showHome();
    }
})();
</script>
</body>
</html>
