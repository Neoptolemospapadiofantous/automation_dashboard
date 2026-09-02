<?php

namespace App\Http\Controllers;

use App\Billing\OwnKey;
use App\Models\Team;
use App\Models\TeamProviderKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bring-your-own-key settings: an Operator team stores its own provider API
 * key, and chat then runs on that key at 0 credits against a monthly message
 * cap (see App\Billing\OwnKey).
 *
 * SECURITY POSTURE: a key enters through store(), is probed live, and from
 * then on only ever leaves as a 4-character hint. It is never sent to the
 * browser, never logged, and never put in a flash message.
 */
class OwnKeyController extends Controller
{
    /** Providers a customer may supply a key for. Google is absent: no BYOK path exists for it. */
    private const PROVIDERS = ['anthropic', 'openai', 'google'];

    public function __construct(private readonly OwnKey $ownKey) {}

    public function index(Request $request): Response
    {
        $team = $this->team($request);
        $plan = $team->planObject();

        return Inertia::render('Settings/OwnKey', [
            'allowed' => $plan->allowsOwnKey(),
            'planLabel' => $plan->label(),
            'messageCap' => $plan->monthlyMessageCap(),
            'messagesUsed' => $this->ownKey->messagesUsed($team),
            'providers' => self::PROVIDERS,
            'keys' => TeamProviderKey::where('team_id', $team->id)
                ->orderBy('provider')
                ->get()
                ->map(fn (TeamProviderKey $k) => [
                    'id' => $k->id,
                    'provider' => $k->provider,
                    'hint' => $k->key_hint,
                    'usable' => $k->isUsable(),
                    'last_verified_at' => $k->last_verified_at?->toIso8601String(),
                    'last_error' => $k->last_error,
                ])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $team = $this->team($request);

        if (! $team->planObject()->allowsOwnKey()) {
            // Enforced server-side as well as in the UI: a downgraded team must
            // not be able to add a key by replaying the request.
            throw ValidationException::withMessages([
                'api_key' => 'Your plan does not include using your own API key.',
            ]);
        }

        $data = $request->validate([
            'provider' => ['required', 'string', 'in:'.implode(',', self::PROVIDERS)],
            'api_key' => ['required', 'string', 'min:20', 'max:400'],
        ]);

        $key = trim($data['api_key']);

        // Probe before storing — never let the first visitor message be the test.
        if ($error = $this->ownKey->verify($data['provider'], $key)) {
            throw ValidationException::withMessages(['api_key' => $error]);
        }

        TeamProviderKey::updateOrCreate(
            ['team_id' => $team->id, 'provider' => $data['provider']],
            [
                'api_key' => $key,
                'key_hint' => TeamProviderKey::hintFor($key),
                'last_verified_at' => now(),
                'last_error' => null,
            ],
        );

        return back()->with('flash', ['banner' => 'Key verified and saved. Chat on this provider now runs on your account.']);
    }

    /**
     * Re-probe a stored key. The UI offers this because keys get revoked and
     * rotated without anyone telling us, and the failure would otherwise
     * surface to a visitor rather than to the operator.
     */
    public function verify(Request $request, TeamProviderKey $ownKey): RedirectResponse
    {
        $this->authorizeKey($request, $ownKey);

        $error = $this->ownKey->verify($ownKey->provider, $ownKey->api_key);

        $ownKey->forceFill([
            'last_error' => $error,
            'last_verified_at' => $error === null ? now() : $ownKey->last_verified_at,
        ])->save();

        return back()->with('flash', [
            'banner' => $error ?? 'Key re-verified successfully.',
            'bannerStyle' => $error ? 'danger' : 'success',
        ]);
    }

    public function destroy(Request $request, TeamProviderKey $ownKey): RedirectResponse
    {
        $this->authorizeKey($request, $ownKey);
        $ownKey->delete();

        return back()->with('flash', ['banner' => 'Key removed. Chat is back on your credit balance.']);
    }

    private function team(Request $request): Team
    {
        $team = $request->user()?->currentTeam;
        abort_unless($team instanceof Team, 403);

        return $team;
    }

    /** A key may only ever be touched by its own team. */
    private function authorizeKey(Request $request, TeamProviderKey $key): void
    {
        abort_unless($key->team_id === $this->team($request)->id, 403);
    }
}
