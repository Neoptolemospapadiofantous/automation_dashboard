/*!
 * Flowstack Embed Widget (v2)
 * Themed floating launcher + iframe chat panel, with a host JS API
 * (window.flowstack), a proactive teaser, an unread badge, and a
 * domain self-check. Loaded via <script src=".../widget/{slug}.js" defer>.
 */
(function () {
    if (window.__flowstackEmbedLoaded) return;

    var CFG = {!! json_encode($config, JSON_UNESCAPED_SLASHES) !!};
    var IFRAME_URL = {!! json_encode($iframeUrl, JSON_UNESCAPED_SLASHES) !!};
    var AGENT_NAME = {!! json_encode($agentName, JSON_UNESCAPED_SLASHES) !!};
    var ALLOWED = {!! json_encode(array_values($allowedDomains), JSON_UNESCAPED_SLASHES) !!};

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }

    // Domain self-check — UX layer only. The hard, browser-enforced control
    // is the frame-ancestors CSP on the iframe; this just avoids rendering a
    // dead launcher on a domain the operator hasn't allowed.
    function hostAllowed(host) {
        if (!ALLOWED || ALLOWED.length === 0) return true;
        host = (host || '').toLowerCase();
        for (var i = 0; i < ALLOWED.length; i++) {
            var p = (ALLOWED[i] || '').toLowerCase().trim();
            if (!p) continue;
            if (p.indexOf('*.') === 0) {
                var base = p.slice(2);
                if (host === base || host.slice(-(base.length + 1)) === '.' + base) return true;
            } else if (host === p) {
                return true;
            }
        }
        return false;
    }
    if (!hostAllowed(window.location.hostname)) {
        console.warn('[Flowstack] chat widget is not enabled for ' + window.location.hostname
            + ' - add this domain to the agent allowed domains in the dashboard.');
        return;
    }
    window.__flowstackEmbedLoaded = true;

    var ACCENT = CFG.accent_color || '#000000';
    var ONACCENT = CFG.text_color || '#FFFFFF';
    var SIDE = CFG.position === 'left' ? 'left' : 'right';
    var IFRAME_ORIGIN;
    try { IFRAME_ORIGIN = new URL(IFRAME_URL).origin; } catch (e) { IFRAME_ORIGIN = '*'; }

    // Styles — brand: hard edges, offset shadow, ink-on-ground.
    var css = [
        '#fs-embed-btn{position:fixed;bottom:24px;' + SIDE + ':24px;min-width:56px;height:56px;border-radius:0;background:' + ACCENT + ';color:' + ONACCENT + ';border:1px solid ' + ACCENT + ';cursor:pointer;z-index:2147483646;box-shadow:6px 6px 0 rgba(0,0,0,.14);display:flex;align-items:center;justify-content:center;gap:8px;padding:0 16px;font-family:ui-monospace,"JetBrains Mono",monospace;font-size:14px;transition:background .15s,color .15s;}',
        '@media (hover:hover){#fs-embed-btn:hover{background:' + ONACCENT + ';color:' + ACCENT + ';}}',
        '#fs-embed-btn:active{background:' + ONACCENT + ';color:' + ACCENT + ';}',
        '#fs-embed-icon{display:flex;}#fs-embed-icon svg{width:24px;height:24px;display:block;}',
        '#fs-embed-badge{position:absolute;top:-7px;' + SIDE + ':-7px;min-width:18px;height:18px;padding:0 5px;border-radius:9px;background:#e11d48;color:#fff;font:700 11px/18px ui-monospace,monospace;text-align:center;display:none;box-sizing:border-box;}',
        '#fs-embed-badge.show{display:block;}',
        '#fs-embed-teaser{position:fixed;bottom:92px;' + SIDE + ':24px;max-width:260px;background:#fff;color:#111;border:1px solid ' + ACCENT + ';box-shadow:6px 6px 0 rgba(0,0,0,.12);padding:12px 30px 12px 12px;font:14px/1.45 ui-sans-serif,system-ui,sans-serif;z-index:2147483646;cursor:pointer;display:none;}',
        '#fs-embed-teaser.show{display:block;}',
        '#fs-embed-teaser-x{position:absolute;top:3px;right:6px;border:0;background:none;cursor:pointer;font-size:17px;line-height:1;color:#999;}',
        '#fs-embed-frame-wrap{position:fixed;bottom:96px;' + SIDE + ':24px;width:380px;height:min(640px,calc(100vh - 120px));border-radius:0;overflow:hidden;border:1px solid ' + ACCENT + ';box-shadow:8px 8px 0 rgba(0,0,0,.12);background:#fff;z-index:2147483645;display:none;}',
        '#fs-embed-frame-wrap.open{display:block;}',
        '#fs-embed-frame{width:100%;height:100%;border:0;}',
        '@media (max-width:480px){#fs-embed-frame-wrap{bottom:0;left:0;right:0;top:0;width:100%;height:100%;}#fs-embed-teaser{display:none !important;}}'
    ].join('');
    var style = document.createElement('style');
    style.textContent = css;
    document.head.appendChild(style);

    var CHAT_SVG = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.76c0 1.6 1.123 2.994 2.707 3.227 1.068.157 2.148.279 3.238.364.466.037.893.281 1.153.671L12 21l2.652-3.978c.26-.39.687-.634 1.153-.67 1.09-.086 2.17-.208 3.238-.365 1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z"/></svg>';
    var CLOSE_SVG = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>';

    // Launcher button.
    var label = CFG.launcher_text ? '<span>' + esc(CFG.launcher_text) + '</span>' : '';
    var btn = document.createElement('button');
    btn.id = 'fs-embed-btn';
    btn.type = 'button';
    btn.setAttribute('aria-label', 'Chat with ' + AGENT_NAME);
    btn.setAttribute('aria-expanded', 'false');
    btn.innerHTML = '<span id="fs-embed-icon">' + CHAT_SVG + '</span>' + label
        + '<span id="fs-embed-badge" aria-hidden="true"></span>';
    document.body.appendChild(btn);
    var icon = btn.querySelector('#fs-embed-icon');
    var badge = btn.querySelector('#fs-embed-badge');

    // Panel + iframe. Parent host forwarded via ?ref= for the backend check.
    var wrap = document.createElement('div');
    wrap.id = 'fs-embed-frame-wrap';
    var sep = IFRAME_URL.indexOf('?') >= 0 ? '&' : '?';
    var frameSrc = IFRAME_URL + sep + 'ref=' + encodeURIComponent(window.location.hostname);
    wrap.innerHTML = '<iframe id="fs-embed-frame" src="' + frameSrc + '" '
        + 'allow="microphone; clipboard-write" title="Chat with ' + esc(AGENT_NAME) + '"></iframe>';
    document.body.appendChild(wrap);
    var frame = wrap.querySelector('#fs-embed-frame');

    // State + events.
    var opened = false;
    var unread = 0;
    var listeners = {};
    function emit(ev, data) { (listeners[ev] || []).forEach(function (fn) { try { fn(data); } catch (e) {} }); }
    function postToFrame(msg) { if (frame && frame.contentWindow) frame.contentWindow.postMessage(msg, IFRAME_ORIGIN); }
    function renderBadge() {
        if (unread > 0 && !opened) { badge.textContent = unread > 9 ? '9+' : String(unread); badge.classList.add('show'); }
        else { badge.classList.remove('show'); }
    }
    function setIcon() { icon.innerHTML = opened ? CLOSE_SVG : CHAT_SVG; }

    function open() {
        if (opened) return;
        opened = true; unread = 0;
        wrap.classList.add('open'); setIcon(); renderBadge(); hideTeaser();
        btn.setAttribute('aria-expanded', 'true');
        postToFrame({ type: 'fs:visible' });
        emit('open');
    }
    function close() {
        if (!opened) return;
        opened = false;
        wrap.classList.remove('open'); setIcon();
        btn.setAttribute('aria-expanded', 'false');
        emit('close');
    }
    function toggle() { opened ? close() : open(); }
    btn.addEventListener('click', toggle);

    // Proactive teaser + auto-open.
    var teaser = null;
    function hideTeaser() { if (teaser) teaser.classList.remove('show'); }
    function showTeaser() {
        if (opened || teaser) return;
        teaser = document.createElement('div');
        teaser.id = 'fs-embed-teaser';
        teaser.innerHTML = '<button id="fs-embed-teaser-x" type="button" aria-label="Dismiss">x</button>'
            + '<span>' + esc(CFG.proactive_message) + '</span>';
        document.body.appendChild(teaser);
        teaser.querySelector('#fs-embed-teaser-x').addEventListener('click', function (e) {
            e.stopPropagation(); hideTeaser();
        });
        teaser.addEventListener('click', open);
        requestAnimationFrame(function () { teaser.classList.add('show'); });
    }
    var delayMs = Math.max(0, (CFG.proactive_delay || 0)) * 1000;
    if (CFG.proactive_message) setTimeout(showTeaser, delayMs);
    if (CFG.auto_open) setTimeout(function () { open(); }, delayMs);

    // Inbound messages from the iframe (origin-checked).
    window.addEventListener('message', function (e) {
        if (IFRAME_ORIGIN !== '*' && e.origin !== IFRAME_ORIGIN) return;
        var d = e.data || {};
        if (d.type === 'fs:message') {
            emit('message', d);
            if (!opened) { unread++; renderBadge(); }
        } else if (d.type === 'fs:lead') {
            emit('lead', d.lead || d);
        } else if (d.type === 'fs:ready') {
            emit('ready', d);
        }
    });

    // Host JS API — the customer's page can drive the widget.
    window.flowstack = {
        open: open,
        close: close,
        toggle: toggle,
        sendMessage: function (text) { open(); postToFrame({ type: 'fs:send', text: String(text == null ? '' : text) }); },
        on: function (ev, fn) { (listeners[ev] = listeners[ev] || []).push(fn); return window.flowstack; },
        config: CFG
    };
})();
