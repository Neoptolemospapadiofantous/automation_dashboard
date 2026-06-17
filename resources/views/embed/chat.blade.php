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
           external fonts. Hard edges (radius 0) per the brand. */
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
        header h1 { font-size: 14px; font-weight: 600; margin: 0; }
        /* Art. 50 disclosure — mono annotation, must stay legible on the
           light header (was once white-on-white; never again). */
        header .ai-disclosure {
            font-size: 10px; color: var(--ink-dim); margin: 2px 0 0;
            font-family: var(--font-mono); letter-spacing: 0.04em;
        }
        header .badge {
            width: 32px; height: 32px; border-radius: 0;
            background: var(--ink); color: var(--bg);
            display: flex; align-items: center; justify-content: center;
            font-size: 14px; font-weight: 700;
            font-family: var(--font-mono);
        }
        #thread {
            flex: 1; overflow-y: auto;
            padding: 16px; display: flex; flex-direction: column; gap: 8px;
        }
        .msg {
            max-width: 80%; padding: 10px 12px;
            border-radius: 0; font-size: 14px; line-height: 1.4;
            white-space: pre-wrap; word-wrap: break-word;
        }
        .msg.user {
            align-self: flex-end;
            background: var(--ink); color: var(--bg);
        }
        .msg.agent {
            align-self: flex-start;
            background: var(--surface); color: var(--ink);
            border: 1px solid var(--border-line);
        }
        .msg.system {
            align-self: center;
            background: transparent; color: var(--ink-dim);
            font-size: 11px; font-family: var(--font-mono);
        }
        form {
            display: flex; padding: 12px;
            border-top: 1px solid var(--border-line);
            gap: 8px; background: var(--bg);
        }
        form input {
            flex: 1; padding: 10px 12px;
            border: 1px solid var(--border-hi); border-radius: 0;
            font-size: 14px; outline: none;
            background: var(--bg); color: var(--ink);
        }
        form input:focus { border-color: var(--ink); box-shadow: 0 0 0 2px var(--ink); }
        form button {
            padding: 10px 16px; border: 1px solid var(--ink); border-radius: 0;
            background: var(--ink); color: var(--bg);
            font-weight: 600; font-size: 12px; cursor: pointer;
            font-family: var(--font-mono);
            letter-spacing: 0.06em; text-transform: uppercase;
            white-space: nowrap; flex-shrink: 0;
            transition: background .15s, color .15s;
        }
        /* :active alongside :hover so touch devices get press feedback and
           the inverted state doesn't "stick" after tap. */
        form button:hover:not(:disabled),
        form button:active:not(:disabled) { background: var(--bg); color: var(--ink); }
        form button:disabled { opacity: .5; cursor: not-allowed; }
        /* KB source chips — secondary metadata beneath an agent turn.
           Aligned to the agent bubble (flex-start), muted mono to read as
           provenance rather than message content. */
        .sources {
            align-self: flex-start;
            display: flex; flex-wrap: wrap; gap: 4px;
            max-width: 80%; margin-top: -4px;
        }
        .sources .source {
            border: 1px solid var(--border-line); border-radius: 0;
            background: var(--bg); color: var(--ink-dim);
            padding: 2px 6px; font-size: 10px;
            font-family: var(--font-mono); letter-spacing: 0.02em;
        }
        .sources .source.more { border: 0; opacity: .6; padding: 2px 2px; }
        .typing {
            align-self: flex-start;
            background: var(--surface); color: var(--ink-dim);
            border: 1px solid var(--border-line);
            padding: 10px 12px; border-radius: 0;
            font-size: 13px;
        }
        .powered {
            text-align: center; padding: 6px 12px;
            color: var(--ink-mute); font-size: 10px;
            font-family: var(--font-mono); letter-spacing: 0.06em;
            border-top: 1px solid var(--border-line); background: var(--bg-elev);
        }
        .powered a { color: var(--ink-dim); text-decoration: underline; text-underline-offset: 2px; }
    </style>
</head>
<body>
<header>
    <div class="badge">{{ substr($agentName, 0, 1) }}</div>
    <div>
        <h1>{{ $agentName }}</h1>
        {{-- EU AI Act Art. 50 transparency: rendered by the PLATFORM,
             independent of agent scripting, at every conversation. --}}
        <p class="ai-disclosure">AI assistant — not a person. You can ask for a human at any time.</p>
    </div>
</header>

<div id="thread" role="log" aria-live="polite"></div>

<form id="composer" autocomplete="off">
    <input id="msg" type="text" placeholder="Type a message…" required maxlength="2000" autofocus>
    <button type="submit" id="send">Send</button>
</form>

<div class="powered">Powered by <a href="https://flowstack.run" target="_blank" rel="noopener">Flowstack</a></div>

<script>
(function () {
    var slug = {!! json_encode($slug) !!};
    var thread = document.getElementById('thread');
    var form = document.getElementById('composer');
    var input = document.getElementById('msg');
    var sendBtn = document.getElementById('send');
    var visitorId = null;
    var csrf = document.querySelector('meta[name="csrf-token"]').content;

    function addMsg(role, text) {
        var d = document.createElement('div');
        d.className = 'msg ' + role;
        d.textContent = text;
        thread.appendChild(d);
        thread.scrollTop = thread.scrollHeight;
        return d;
    }

    // Render deduped KB source chips beneath an agent turn. citations is the
    // raw trace payload array; may be null/undefined/empty (greetings,
    // low-confidence answers, no-KB replies) in which case nothing renders.
    function addSources(citations) {
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
        thread.appendChild(wrap);
        thread.scrollTop = thread.scrollHeight;
    }

    function addTyping() {
        var d = document.createElement('div');
        d.className = 'typing';
        d.textContent = '…';
        thread.appendChild(d);
        thread.scrollTop = thread.scrollHeight;
        return d;
    }

    function renderTraces(traces) {
        if (!Array.isArray(traces)) return;
        traces.forEach(function (t) {
            if (t.type === 'text' && t.payload && t.payload.message) {
                addMsg('agent', t.payload.message);
                addSources(t.payload.citations);
            } else if (t.type === 'speak' && t.payload && t.payload.message) {
                addMsg('agent', t.payload.message);
                addSources(t.payload.citations);
            }
        });
    }

    function callJson(path, body) {
        return fetch(path, {
            method: 'POST',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'Accept': 'application/json',
            },
            body: body ? JSON.stringify(body) : '{}',
        }).then(function (r) {
            return r.json().then(function (j) {
                return { status: r.status, body: j };
            });
        });
    }

    async function launch() {
        try {
            var r = await callJson('/embed/' + encodeURIComponent(slug) + '/launch');
            if (r.status !== 200) {
                addMsg('system', r.body.error || 'Could not start the chat.');
                sendBtn.disabled = true;
                return;
            }
            visitorId = r.body.visitor_id;
            renderTraces(r.body.traces);
        } catch (e) {
            addMsg('system', 'Connection failed. Please refresh.');
        }
    }

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        var text = input.value.trim();
        if (!text || !visitorId) return;
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
                addMsg('system', r.body.error || 'Could not deliver the message.');
            } else {
                renderTraces(r.body.traces);
            }
        } catch (err) {
            typing.remove();
            addMsg('system', 'Connection failed.');
        } finally {
            sendBtn.disabled = false;
            input.focus();
        }
    });

    launch();
})();
</script>
</body>
</html>
