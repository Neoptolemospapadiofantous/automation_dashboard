<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesByTeamRole;
use App\Jobs\DeliverWebhook;
use App\Models\Team;
use App\Models\TeamWebhook;
use App\Support\PublicWebPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Outbound webhooks — Settings → Webhooks. Owner-only, paid plans only:
 * an integration surface is a paid feature, and a Free team's traffic is
 * capped anyway. The signing secret is shown ONCE, in the flash after
 * creation, and never again (it is stored encrypted and hidden).
 */
class WebhookController extends Controller
{
    use AuthorizesByTeamRole;

    public function __construct(private readonly PublicWebPage $guard) {}

    public function index(Request $request): Response
    {
        $team = $this->team($request);

        return Inertia::render('Settings/Webhooks', [
            'allowed' => $team->planObject()->isPaid(),
            'planLabel' => $team->planObject()->label(),
            'events' => TeamWebhook::EVENTS,
            'webhooks' => TeamWebhook::query()
                ->where('team_id', $team->id)
                ->orderBy('id')
                ->get()
                ->map(fn (TeamWebhook $hook) => [
                    'id' => $hook->id,
                    'url' => $hook->url,
                    'events' => $hook->events,
                    'active' => $hook->active,
                    'last_status' => $hook->last_status,
                    'last_error' => $hook->last_error,
                    'last_delivered_at' => $hook->last_delivered_at?->toIso8601String(),
                    'failure_count' => $hook->failure_count,
                ])->values(),
            // The secret of a webhook created on the previous request — shown once.
            'newSecret' => $request->session()->get('webhook_secret'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $team = $this->team($request);
        $this->requireOwner($request, 'add webhooks');

        if (! $team->planObject()->isPaid()) {
            throw ValidationException::withMessages(['url' => 'Webhooks are available on paid plans.']);
        }

        $data = $request->validate([
            'url' => ['required', 'string', 'url', 'max:2000', 'starts_with:https://,http://'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', 'in:'.implode(',', array_keys(TeamWebhook::EVENTS))],
        ]);

        try {
            $this->guard->assertPublicHttpUrl($data['url']);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['url' => $e->getMessage()]);
        }

        if (TeamWebhook::query()->where('team_id', $team->id)->count() >= 10) {
            throw ValidationException::withMessages(['url' => 'A team can register up to 10 webhooks.']);
        }

        $secret = 'whsec_'.Str::random(40);

        TeamWebhook::create([
            'team_id' => $team->id,
            'url' => $data['url'],
            'secret' => $secret,
            'events' => array_values(array_unique($data['events'])),
            'active' => true,
        ]);

        return back()
            ->with('webhook_secret', $secret)
            ->with('flash', ['banner' => 'Webhook added. Copy the signing secret now — it will not be shown again.']);
    }

    /**
     * Send a synthetic event to the endpoint so an integrator can see the
     * shape and check their signature code before a real lead arrives.
     */
    public function test(Request $request, TeamWebhook $webhook): RedirectResponse
    {
        $this->authorizeHook($request, $webhook);

        DeliverWebhook::dispatch($webhook->id, [
            'id' => (string) Str::ulid(),
            'event' => 'webhook.test',
            'created_at' => now()->toIso8601String(),
            'team_id' => $webhook->team_id,
            'data' => ['message' => 'This is a test delivery from Flowstack.', 'webhook_id' => $webhook->id],
        ]);

        return back()->with('flash', ['banner' => 'Test event queued. The result appears on this page once it has been delivered.']);
    }

    public function toggle(Request $request, TeamWebhook $webhook): RedirectResponse
    {
        $this->authorizeHook($request, $webhook);

        $webhook->forceFill([
            'active' => ! $webhook->active,
            // Re-enabling clears the strike count so a fixed endpoint gets a fresh run.
            'failure_count' => $webhook->active ? $webhook->failure_count : 0,
        ])->save();

        return back();
    }

    public function destroy(Request $request, TeamWebhook $webhook): RedirectResponse
    {
        $this->authorizeHook($request, $webhook);
        $webhook->delete();

        return back()->with('flash', ['banner' => 'Webhook removed.']);
    }

    private function team(Request $request): Team
    {
        $team = $request->user()->currentTeam;
        abort_unless($team instanceof Team, 403, 'Sign in to a team first.');

        return $team;
    }

    private function authorizeHook(Request $request, TeamWebhook $webhook): void
    {
        abort_unless($webhook->team_id === $this->team($request)->id, 403);
        $this->requireOwner($request, 'manage webhooks');
    }
}
