<?php

namespace App\Http\Controllers;

use App\Billing\OwnKey;
use App\Models\Agent;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Install Flowstack on your website" — the embed snippet + the widget
 * appearance/behavior editor + the domain allowlist, all for the team's
 * CURRENT agent. Switching agents in the top-left dropdown changes which
 * agent this page configures.
 */
class InstallController extends Controller
{
    public function index(Request $request, OwnKey $ownKey): Response
    {
        $team = $request->user()->currentTeam;
        $agent = $team instanceof Team ? $team->currentAgent : null;

        return Inertia::render('Install/Index', [
            'agent' => $agent instanceof Agent ? [
                'slug' => $agent->slug,
                'name' => $agent->name,
                'status' => $agent->status,
                'widget_config' => $agent->widgetConfig(),
                'allowed_domains' => $agent->allowedDomains(),
            ] : null,
            // The page's billing note must not claim "debits credits" for a
            // team whose embedded chat is running on its own provider key.
            'own_key' => $agent instanceof Agent ? [
                'active' => $ownKey->coversAgent($agent),
                'provider' => $ownKey->providerFor($agent),
                'used' => $ownKey->messagesUsed($team),
                'cap' => $team->planObject()->monthlyMessageCap(),
            ] : null,
        ]);
    }

    /**
     * Persist the widget appearance/behavior + domain allowlist for the
     * current agent. The widget JS is edge-cached ~5 min, so changes
     * propagate within that window.
     */
    public function update(Request $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;
        $agent = $team instanceof Team ? $team->currentAgent : null;
        abort_unless($agent instanceof Agent, 404, 'No current agent to configure.');

        $data = $request->validate([
            'accent_color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'text_color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'position' => ['required', 'in:right,left'],
            'launcher_text' => ['nullable', 'string', 'max:40'],
            'title' => ['nullable', 'string', 'max:60'],
            'subtitle' => ['nullable', 'string', 'max:80'],
            'avatar_url' => ['nullable', 'url', 'max:2048'],
            'proactive_message' => ['nullable', 'string', 'max:200'],
            'proactive_delay' => ['required', 'integer', 'min:0', 'max:120'],
            'auto_open' => ['required', 'boolean'],
            'show_branding' => ['required', 'boolean'],
            'welcome_message' => ['nullable', 'string', 'max:280'],
            'starter_prompts' => ['nullable', 'array', 'max:6'],
            'starter_prompts.*' => ['string', 'max:120'],
            'allowed_domains' => ['nullable', 'array', 'max:50'],
            'allowed_domains.*' => ['string', 'max:255'],
        ]);

        $agent->widget_config = [
            'accent_color' => $data['accent_color'],
            'text_color' => $data['text_color'],
            'position' => $data['position'],
            'launcher_text' => (string) ($data['launcher_text'] ?? ''),
            'title' => (string) ($data['title'] ?? ''),
            'subtitle' => (string) ($data['subtitle'] ?? ''),
            'avatar_url' => (string) ($data['avatar_url'] ?? ''),
            'proactive_message' => (string) ($data['proactive_message'] ?? ''),
            'proactive_delay' => (int) $data['proactive_delay'],
            'auto_open' => (bool) $data['auto_open'],
            'show_branding' => (bool) $data['show_branding'],
            'welcome_message' => (string) ($data['welcome_message'] ?? ''),
            'starter_prompts' => array_values(array_filter(array_map(
                fn ($p) => trim((string) $p),
                $data['starter_prompts'] ?? [],
            ), fn (string $p) => $p !== '')),
        ];

        $agent->allowed_domains = $this->normalizeDomains($data['allowed_domains'] ?? []);
        $agent->save();

        return back()->with('status', 'widget-updated');
    }

    /**
     * Strip scheme/path/port, lowercase, dedupe — store bare hosts (wildcard
     * prefix preserved). Keeps allowlist matching predictable.
     *
     * @param  array<int, string>  $domains
     * @return list<string>
     */
    protected function normalizeDomains(array $domains): array
    {
        return collect($domains)
            ->map(fn ($d) => strtolower(trim((string) $d)))
            ->map(function (string $d): string {
                $d = (string) preg_replace('#^[a-z]+://#', '', $d); // drop scheme
                $d = explode('/', $d)[0];                            // drop path

                return explode(':', $d)[0];                          // drop port
            })
            // Reject anything that isn't a bare host (optionally "*." prefixed).
            // Critically this drops spaces / ";" / control chars so a value can
            // never inject extra directives into the frame-ancestors CSP header.
            ->filter(fn (string $d) => $d !== '' && preg_match('/^\*?[a-z0-9.-]+$/', $d) === 1)
            ->unique()
            ->values()
            ->all();
    }
}
