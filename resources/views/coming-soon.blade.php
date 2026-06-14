<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Flowstack — Coming soon</title>
    <style>
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; }
        body {
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #0b0b0f; color: #ececf1;
            display: flex; align-items: center; justify-content: center; padding: 24px;
        }
        .card { max-width: 32rem; width: 100%; text-align: center; }
        .brand {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            letter-spacing: 0.28em; font-size: 0.8rem; font-weight: 600;
            color: #8b5cf6; text-transform: uppercase;
        }
        h1 { margin: 1.25rem 0 0.75rem; font-size: clamp(1.9rem, 6vw, 2.75rem); font-weight: 650; line-height: 1.1; }
        p { margin: 0 auto; max-width: 28rem; color: #a1a1aa; line-height: 1.6; }
        .dot {
            display: inline-block; width: 7px; height: 7px; border-radius: 9999px;
            background: #8b5cf6; margin-right: 8px; vertical-align: middle;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(139,92,246,.5); }
            70% { box-shadow: 0 0 0 10px rgba(139,92,246,0); }
            100% { box-shadow: 0 0 0 0 rgba(139,92,246,0); }
        }
        form { margin: 1.75rem auto 0; display: flex; gap: 8px; max-width: 26rem; }
        input[type=email] {
            flex: 1; min-width: 0; padding: 0.7rem 0.9rem; border-radius: 0;
            border: 1px solid #2a2a33; background: #15151c; color: #ececf1; font-size: 0.95rem;
        }
        input[type=email]:focus { outline: none; border-color: #8b5cf6; }
        button {
            padding: 0.7rem 1.1rem; border: 0; border-radius: 0; cursor: pointer;
            background: #8b5cf6; color: #0b0b0f; font-weight: 600; font-size: 0.95rem;
        }
        button:hover { opacity: .9; }
        .hp { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
        .err { margin-top: 0.75rem; color: #fca5a5; font-size: 0.85rem; }
        .ok { margin-top: 1.5rem; color: #86efac; font-weight: 500; }
        .foot { margin-top: 2rem; font-size: 0.85rem; color: #6b7280; }
        a { color: #c4b5fd; text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <main class="card">
        <div class="brand">Flowstack</div>
        <h1><span class="dot"></span>Coming soon</h1>

        @if (($submitted ?? false))
            <p class="ok">You're on the list — we'll email you the moment we launch. Thanks! 🎉</p>
        @else
            <p>AI assistants that answer your visitors and capture leads. We're putting on the finishing touches — leave your email and we'll let you know when it's live.</p>

            <form method="POST" action="{{ route('waitlist.store') }}" novalidate>
                {{-- honeypot: bots fill this; humans never see it --}}
                <input type="text" name="company" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
                <input type="email" name="email" placeholder="you@company.com" value="{{ $email ?? '' }}" required aria-label="Email address">
                <button type="submit">Join the waitlist</button>
            </form>
            @if (! empty($error))
                <p class="err">{{ $error }}</p>
            @endif
        @endif

        <p class="foot">Questions? <a href="mailto:hello@flowstack.run">hello@flowstack.run</a></p>
    </main>
</body>
</html>
