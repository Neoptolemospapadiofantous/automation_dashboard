/*!
 * Flowstack Embed Widget
 * Renders a floating chat button + iframe modal.
 * Loaded by the customer's site via <script src=".../widget/{slug}.js" defer>
 */
(function () {
    if (window.__flowstackEmbedLoaded) return;
    window.__flowstackEmbedLoaded = true;

    var IFRAME_URL = {!! json_encode($iframeUrl, JSON_UNESCAPED_SLASHES) !!};
    var INK = {!! json_encode($ink, JSON_UNESCAPED_SLASHES) !!};
    var BG = {!! json_encode($bg, JSON_UNESCAPED_SLASHES) !!};
    var AGENT_NAME = {!! json_encode($agentName, JSON_UNESCAPED_SLASHES) !!};

    // Flowstack blueprint brand: hard edges (radius 0), ink block button,
    // hover inverts ink/ground — mirrors the marketing site's .btn-grad.
    var css = ''
        + '#fs-embed-btn {'
        + '  position: fixed; bottom: 24px; right: 24px;'
        + '  width: 56px; height: 56px; border-radius: 0;'
        + '  background: ' + INK + '; color: ' + BG + ';'
        + '  border: 1px solid ' + INK + '; cursor: pointer; z-index: 2147483646;'
        + '  box-shadow: 6px 6px 0 rgba(0,0,0,.14);'
        + '  display: flex; align-items: center; justify-content: center;'
        + '  font-family: ui-monospace, "JetBrains Mono", monospace;'
        + '  transition: background .15s, color .15s;'
        + '}'
        // Invert on hover/active. Guard hover behind (hover: hover) so touch
        // devices don't get a "stuck" inverted button after tap; :active gives
        // them press feedback instead.
        + '@media (hover: hover) { #fs-embed-btn:hover { background: ' + BG + '; color: ' + INK + '; } }'
        + '#fs-embed-btn:active { background: ' + BG + '; color: ' + INK + '; }'
        + '#fs-embed-btn svg { width: 24px; height: 24px; }'
        + '#fs-embed-frame-wrap {'
        + '  position: fixed; bottom: 96px; right: 24px;'
        + '  width: 380px; height: min(640px, calc(100vh - 120px));'
        + '  border-radius: 0; overflow: hidden;'
        + '  border: 1px solid ' + INK + ';'
        + '  box-shadow: 8px 8px 0 rgba(0,0,0,.12);'
        + '  background: ' + BG + '; z-index: 2147483645;'
        + '  display: none;'
        + '}'
        + '#fs-embed-frame-wrap.open { display: block; }'
        + '#fs-embed-frame {'
        + '  width: 100%; height: 100%; border: 0;'
        + '}'
        + '@media (max-width: 480px) {'
        + '  #fs-embed-frame-wrap {'
        + '    bottom: 0; right: 0; left: 0; top: 0;'
        + '    width: 100%; height: 100%; border-radius: 0;'
        + '  }'
        + '}';

    var style = document.createElement('style');
    style.textContent = css;
    document.head.appendChild(style);

    var btn = document.createElement('button');
    btn.id = 'fs-embed-btn';
    btn.setAttribute('aria-label', 'Chat with ' + AGENT_NAME);
    btn.innerHTML = ''
        + '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">'
        + '<path stroke-linecap="round" stroke-linejoin="round"'
        + ' d="M2.25 12.76c0 1.6 1.123 2.994 2.707 3.227 1.068.157 2.148.279 3.238.364.466.037.893.281 1.153.671L12 21l2.652-3.978c.26-.39.687-.634 1.153-.67 1.09-.086 2.17-.208 3.238-.365 1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z"/>'
        + '</svg>';
    document.body.appendChild(btn);

    var wrap = document.createElement('div');
    wrap.id = 'fs-embed-frame-wrap';
    wrap.innerHTML = '<iframe id="fs-embed-frame" src="' + IFRAME_URL + '" '
        + 'allow="microphone; clipboard-write" '
        + 'title="Chat with ' + AGENT_NAME.replace(/"/g, '&quot;') + '">'
        + '</iframe>';
    document.body.appendChild(wrap);

    btn.addEventListener('click', function () {
        var isOpen = wrap.classList.contains('open');
        wrap.classList.toggle('open');
        // Reload the iframe on first open so the chat starts fresh; preserve
        // state on subsequent opens (the cookie persists the visitor session).
        if (!isOpen && !wrap.dataset.loaded) {
            wrap.dataset.loaded = '1';
        }
        btn.setAttribute('aria-expanded', String(!isOpen));
    });
})();
