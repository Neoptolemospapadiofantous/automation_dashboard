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
            background: #0b0b0f;
            color: #ececf1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .card { max-width: 30rem; text-align: center; }
        .brand {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            letter-spacing: 0.28em;
            font-size: 0.8rem;
            font-weight: 600;
            color: #8b5cf6;
            text-transform: uppercase;
        }
        h1 {
            margin: 1.25rem 0 0.75rem;
            font-size: clamp(1.9rem, 6vw, 2.75rem);
            font-weight: 650;
            line-height: 1.1;
        }
        p { margin: 0 auto; max-width: 26rem; color: #a1a1aa; line-height: 1.6; }
        .dot {
            display: inline-block; width: 7px; height: 7px; border-radius: 9999px;
            background: #8b5cf6; margin-right: 8px; vertical-align: middle;
            box-shadow: 0 0 0 0 rgba(139,92,246,.6); animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%   { box-shadow: 0 0 0 0 rgba(139,92,246,.5); }
            70%  { box-shadow: 0 0 0 10px rgba(139,92,246,0); }
            100% { box-shadow: 0 0 0 0 rgba(139,92,246,0); }
        }
        .foot { margin-top: 2rem; font-size: 0.85rem; color: #6b7280; }
        a { color: #c4b5fd; text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <main class="card">
        <div class="brand">Flowstack</div>
        <h1><span class="dot"></span>Coming soon</h1>
        <p>We're putting the finishing touches on the platform. It'll be open for sign-ups shortly — thanks for your patience.</p>
        <p class="foot">Questions? <a href="mailto:hello@flowstack.run">hello@flowstack.run</a></p>
    </main>
</body>
</html>
