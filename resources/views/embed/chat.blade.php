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
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
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
        html, body { margin: 0; height: 100%; }
        body {
            font-family: ui-sans-serif, system-ui, sans-serif;
            background: var(--bg);
            color: var(--ink);
            display: flex;
            flex-direction: column;
            height: 100%;
        }
        header {
            padding: 14px 16px;
            border-bottom: 1px solid var(--border-line);
            display: flex;
            align-items: center;
            gap: 10px;
            background: var(--bg-elev);
        }
        header h1 { font-size: 14px; font-weight: 600; margin: 0; display: flex; align-items: center; gap: 6px; }
        header .ai-disclosure {
            font-size: 10px; color: var(--ink-dim); margin: 2px 0 0;
            font-family: var(--font-mono); letter-spacing: 0.04em;
        }
        header .head-text { flex: 1; min-width: 0; }
        header .subtitle {
            font-size: 11px; color: var(--ink-dim); margin: 1px 0 0;
            display: flex; align-items: center; gap: 6px;
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
            margin-left: auto; flex: none;
            width: 30px; height: 30px; padding: 0;
            border: 1px solid transparent; border-radius: 0;
            background: transparent; color: var(--ink-dim);
            cursor: pointer; display: flex; align-items: center; justify-content: center;
            transition: background .15s, color .15s, border-color .15s;
        }
        header .close-btn:hover { background: var(--surface-hi); color: var(--ink); border-color: var(--border-line); }
        header .close-btn svg { width: 16px; height: 16px; display: block; }
        #thread {
            flex: 1; overflow-y: auto;
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
        form input {
            flex: 1; padding: 10px 12px;
            border: 1px solid var(--border-hi); border-radius: 0;
            font-size: 14px; outline: none;
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
            display: flex; flex-wrap: wrap; gap: 4px;
        }
        .sources .source {
            border: 1px solid var(--border-line); border-radius: 0;
            background: var(--bg); color: var(--ink-dim);
            padding: 2px 6px; font-size: 10px;
            font-family: var(--font-mono); letter-spacing: 0.02em;
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
            text-align: center; padding: 6px 12px;
            color: var(--ink-mute); font-size: 10px;
            font-family: var(--font-mono); letter-spacing: 0.06em;
            border-top: 1px solid var(--border-line); background: var(--bg-elev);
        }
        .powered a { color: var(--ink-dim); text-decoration: underline; text-underline-offset: 2px; }
        @media (prefers-reduced-motion: reduce) {
            #thread { scroll-behavior: auto; }
            .typing .dot, header .status-dot { animation: none; }
        }
    </style>
</head>
<body>
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
        {{-- EU AI Act Art. 50 transparency: rendered by the PLATFORM,
             independent of agent scripting, at every conversation. --}}
        <p class="ai-disclosure">AI assistant — not a person. You can ask for a human at any time.</p>
    </div>
    <button type="button" id="fs-close" class="close-btn" aria-label="Close chat">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
    </button>
</header>

<div id="thread" role="log" aria-live="polite"></div>

<form id="composer" autocomplete="off">
    <input id="msg" type="text" placeholder="Type a message…" required maxlength="2000" autofocus>
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
    var STORAGE_KEY = 'fs_visitor_' + slug;
    var thread = document.getElementById('thread');
    var form = document.getElementById('composer');
    var input = document.getElementById('msg');
    var sendBtn = document.getElementById('send');
    var closeBtn = document.getElementById('fs-close');
    var visitorId = null;
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
    function readVisitor() {
        try { return window.localStorage.getItem(STORAGE_KEY) || null; } catch (e) { return null; }
    }
    function writeVisitor(id) {
        if (!id) return;
        try { window.localStorage.setItem(STORAGE_KEY, id); } catch (e) {}
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

    async function launch() {
        try {
            var stored = readVisitor();
            var r = await callJson('/embed/' + encodeURIComponent(slug) + '/launch',
                stored ? { visitor_id: stored } : {});
            if (r.status !== 200) {
                addSystem(r.body.error || 'Could not start the chat.');
                sendBtn.disabled = true;
                return;
            }
            visitorId = r.body.visitor_id;
            writeVisitor(visitorId);

            var resumed = r.body.resumed && Array.isArray(r.body.transcript) && r.body.transcript.length;
            if (resumed) {
                // Returning visitor: replay history, skip greeting + starters.
                renderTranscript(r.body.transcript);
            } else {
                // New thread: optional welcome, the greeting traces, then starters.
                addWelcome(WELCOME);
                renderTraces(r.body.traces);
                addQuickReplies(STARTERS);
            }
            toParent({ type: 'fs:ready' });
        } catch (e) {
            addSystem('Connection failed. Please refresh.');
        }
    }

    async function send(text) {
        text = (text || '').trim();
        if (!text || !visitorId || sendBtn.disabled) return;
        // Tapping a chip should clear any other lingering chip groups.
        Array.prototype.slice.call(thread.querySelectorAll('.quick')).forEach(function (q) { q.remove(); });
        addMsg('user', text);
        input.value = '';
        sendBtn.disabled = true;
        var typing = addTyping();
        try {
            var r = await callJson('/embed/' + encodeURIComponent(slug) + '/interact', {
                visitor_id: visitorId,
                message: text,
            });
            typing.remove();
            if (r.status !== 200) {
                addSystem(r.body.error || 'Could not deliver the message.');
            } else {
                renderTraces(r.body.traces);
            }
        } catch (err) {
            typing.remove();
            addSystem('Connection failed.');
        } finally {
            sendBtn.disabled = false;
            input.focus();
        }
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        send(input.value);
    });

    // Header close button -> loader closes the panel.
    closeBtn.addEventListener('click', function () {
        toParent({ type: 'fs:close' });
    });

    // Host JS API bridge: the loader relays window.flowstack.sendMessage()
    // and open() here.
    window.addEventListener('message', function (e) {
        var d = e.data || {};
        if (d.type === 'fs:send' && typeof d.text === 'string') {
            send(d.text);
        } else if (d.type === 'fs:visible') {
            input.focus();
        }
    });

    launch();
})();
</script>
</body>
</html>
