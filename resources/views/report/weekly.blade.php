{{-- Public weekly report: read by people with no account, on a link
     forwarded from the Monday email. Self-contained (no app shell, no
     Vite), white sheet only, the §3.4 ink-on-paper look with the one
     signal yellow. Numbers come from App\Support\WeeklyReport — identical
     to the digest. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $stats['team'] }} — week in review</title>
    <style>
        :root { --ink: #111111; --dim: #555555; --mute: #8a8a8a; --line: #d9d9d9; --bg: #ffffff; --surface: #f6f6f4; --signal: #F5C518; --signal-ink: #8A6A00; }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--ink); font: 15px/1.5 -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        .sheet { max-width: 760px; margin: 0 auto; padding: 32px 16px 64px; }
        .ref { font: 600 11px/1 ui-monospace, SFMono-Regular, Menlo, monospace; letter-spacing: .12em; text-transform: uppercase; color: var(--dim); }
        h1 { font-size: 26px; line-height: 1.2; margin: 8px 0 4px; }
        .sub { color: var(--dim); margin: 0 0 24px; }
        .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        @media (min-width: 560px) { .grid { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
        .tile { border: 1px solid var(--line); padding: 12px 14px; background: var(--bg); }
        .tile .k { font: 600 11px/1 ui-monospace, SFMono-Regular, Menlo, monospace; letter-spacing: .1em; text-transform: uppercase; color: var(--mute); }
        .tile .v { font-size: 28px; font-weight: 600; font-variant-numeric: tabular-nums; margin-top: 6px; }
        .tile .n { color: var(--dim); font-size: 12px; margin-top: 2px; }
        section { margin-top: 28px; }
        h2 { font-size: 13px; letter-spacing: .12em; text-transform: uppercase; color: var(--dim); margin: 0 0 10px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
        .bars { display: flex; align-items: flex-end; gap: 6px; height: 90px; border-bottom: 1px solid var(--line); padding-bottom: 4px; }
        .bar { flex: 1; background: var(--signal); min-height: 2px; position: relative; }
        .bar span { position: absolute; top: -18px; left: 0; right: 0; text-align: center; font: 11px ui-monospace, Menlo, monospace; color: var(--dim); }
        .days { display: flex; gap: 6px; margin-top: 4px; }
        .days div { flex: 1; text-align: center; font: 11px ui-monospace, Menlo, monospace; color: var(--mute); }
        ul { padding-left: 18px; margin: 0; }
        li { margin: 4px 0; }
        table { width: 100%; border-collapse: collapse; font-variant-numeric: tabular-nums; }
        th, td { text-align: left; padding: 8px 6px; border-bottom: 1px solid var(--line); }
        th { font: 600 11px/1 ui-monospace, Menlo, monospace; letter-spacing: .1em; text-transform: uppercase; color: var(--mute); }
        td.num, th.num { text-align: right; }
        .foot { margin-top: 40px; border-top: 1px solid var(--line); padding-top: 12px; color: var(--mute); font-size: 12px; }
        .foot a { color: var(--signal-ink); }
        .pill { display: inline-block; border: 1px solid var(--line); padding: 2px 8px; font: 11px ui-monospace, Menlo, monospace; color: var(--dim); }
    </style>
</head>
<body>
<main class="sheet">
    <div class="ref">Flowstack · Week in review</div>
    <h1>{{ $stats['team'] }}</h1>
    <p class="sub">
        {{ \Illuminate\Support\Carbon::parse($stats['window']['start'])->format('j M') }} –
        {{ \Illuminate\Support\Carbon::parse($stats['window']['end'])->format('j M Y') }}
        · <span class="pill">refreshes itself every week</span>
    </p>

    <div class="grid">
        <div class="tile"><div class="k">Conversations</div><div class="v">{{ number_format($stats['conversations']) }}</div><div class="n">{{ number_format($stats['messages']) }} messages</div></div>
        <div class="tile"><div class="k">Leads</div><div class="v">{{ number_format($stats['leads']) }}</div><div class="n">{{ $stats['qualified'] }} qualified · {{ $stats['won'] }} won</div></div>
        <div class="tile"><div class="k">Asked for a human</div><div class="v">{{ number_format($stats['escalated']) }}</div><div class="n">{{ $stats['escalation_rate'] }}% of conversations</div></div>
        <div class="tile"><div class="k">Satisfaction</div><div class="v">{{ $stats['csat'] === null ? '—' : $stats['csat'].'%' }}</div><div class="n">{{ $stats['csat'] === null ? 'no ratings yet' : 'rated good' }}</div></div>
    </div>

    <section>
        <h2>Conversations by day</h2>
        @php $max = max(1, ...array_map(fn ($d) => $d['conversations'], $stats['daily'])); @endphp
        <div class="bars">
            @foreach ($stats['daily'] as $day)
                <div class="bar" style="height: {{ (int) round(($day['conversations'] / $max) * 100) }}%"><span>{{ $day['conversations'] ?: '' }}</span></div>
            @endforeach
        </div>
        <div class="days">
            @foreach ($stats['daily'] as $day)
                <div>{{ \Illuminate\Support\Carbon::parse($day['date'])->format('D') }}</div>
            @endforeach
        </div>
    </section>

    @if ($stats['gaps'] !== [])
        <section>
            <h2>Questions the knowledge base could not answer</h2>
            <ul>
                @foreach ($stats['gaps'] as $gap)
                    <li>“{{ $gap['question'] }}” — asked {{ $gap['asked_count'] }} {{ $gap['asked_count'] === 1 ? 'time' : 'times' }}</li>
                @endforeach
            </ul>
        </section>
    @endif

    @if (count($stats['agents']) > 1)
        <section>
            <h2>Per agent</h2>
            <table>
                <thead><tr><th>Agent</th><th class="num">Conversations</th><th class="num">Leads</th></tr></thead>
                <tbody>
                @foreach ($stats['agents'] as $agent)
                    <tr><td>{{ $agent['name'] }}</td><td class="num">{{ $agent['conversations'] }}</td><td class="num">{{ $agent['leads'] }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </section>
    @endif

    <section>
        <h2>Housekeeping</h2>
        <ul>
            <li>{{ $stats['stale_leads'] }} {{ $stats['stale_leads'] === 1 ? 'lead is' : 'leads are' }} still waiting on first contact.</li>
            @if ($stats['canned_turns'] > 0)<li>{{ $stats['canned_turns'] }} answers were served instantly from the FAQ.</li>@endif
            @if ($stats['refreshed_docs'] > 0)<li>{{ $stats['refreshed_docs'] }} knowledge {{ $stats['refreshed_docs'] === 1 ? 'page' : 'pages' }} changed on the site and {{ $stats['refreshed_docs'] === 1 ? 'was' : 'were' }} re-read automatically.</li>@endif
            <li>{{ number_format($stats['credits_used']) }} conversation credits used this week.</li>
        </ul>
    </section>

    <p class="foot">
        Generated {{ $generatedAt->format('j M Y, H:i') }} UTC · numbers cover the last {{ $stats['window']['days'] }} full days ·
        <a href="https://www.flowstack.run">Flowstack</a>
    </p>
</main>
</body>
</html>
