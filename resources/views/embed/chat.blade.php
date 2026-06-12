<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $agentName }} · Chat</title>
    <style>
        :root { --primary: #6366f1; }
        * { box-sizing: border-box; }
        html, body { margin: 0; height: 100%; }
        body {
            font-family: ui-sans-serif, system-ui, sans-serif;
            background: #fff;
            color: #111;
            display: flex;
            flex-direction: column;
            height: 100%;
        }
        header {
            padding: 14px 16px;
            border-bottom: 1px solid #f3f4f6;
            display: flex;
            align-items: center;
            gap: 10px;
            background: #fff;
        }
        header h1 { font-size: 14px; font-weight: 600; margin: 0; }
        header .ai-disclosure { font-size: 10px; color: rgba(255,255,255,0.75); margin: 1px 0 0; }
        header .badge {
            width: 32px; height: 32px; border-radius: 50%;
            background: var(--primary); color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 14px; font-weight: 700;
        }
        #thread {
            flex: 1; overflow-y: auto;
            padding: 16px; display: flex; flex-direction: column; gap: 8px;
        }
        .msg {
            max-width: 80%; padding: 10px 12px;
            border-radius: 14px; font-size: 14px; line-height: 1.4;
            white-space: pre-wrap; word-wrap: break-word;
        }
        .msg.user {
            align-self: flex-end;
            background: var(--primary); color: #fff;
            border-bottom-right-radius: 4px;
        }
        .msg.agent {
            align-self: flex-start;
            background: #f3f4f6; color: #111;
            border-bottom-left-radius: 4px;
        }
        .msg.system {
            align-self: center;
            background: transparent; color: #9ca3af;
            font-size: 11px; font-style: italic;
        }
        form {
            display: flex; padding: 12px;
            border-top: 1px solid #f3f4f6;
            gap: 8px; background: #fff;
        }
        form input {
            flex: 1; padding: 10px 12px;
            border: 1px solid #e5e7eb; border-radius: 8px;
            font-size: 14px; outline: none;
        }
        form input:focus { border-color: var(--primary); }
        form button {
            padding: 10px 16px; border: 0; border-radius: 8px;
            background: var(--primary); color: #fff;
            font-weight: 600; font-size: 14px; cursor: pointer;
        }
        form button:disabled { opacity: .5; cursor: not-allowed; }
        .typing {
            align-self: flex-start;
            background: #f3f4f6; color: #6b7280;
            padding: 10px 12px; border-radius: 14px;
            font-size: 13px;
        }
        .powered {
            text-align: center; padding: 6px 12px;
            color: #9ca3af; font-size: 10px;
            border-top: 1px solid #f3f4f6; background: #fafafa;
        }
        .powered a { color: #6366f1; text-decoration: none; }
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

<div class="powered">Powered by <a href="https://flowstack.com" target="_blank" rel="noopener">Flowstack</a></div>

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
            } else if (t.type === 'speak' && t.payload && t.payload.message) {
                addMsg('agent', t.payload.message);
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
