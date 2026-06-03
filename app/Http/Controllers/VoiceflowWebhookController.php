<?php

namespace App\Http\Controllers;

use App\Enums\AssignmentStrategy;
use App\Enums\LeadStatus;
use App\Events\LeadSaved;
use App\Models\Agent;
use App\Models\Lead;
use App\Services\LeadDelegator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inbound webhook for Voiceflow Custom Actions.
 *
 * A Voiceflow agent POSTs a qualified lead here the instant it's captured.
 * The URL is per-agent — {agent:slug} route-binds to the Agent model — and
 * authenticated against that agent's own webhook_secret. team_id is derived
 * from the agent, so the Voiceflow side never has to send (or know) it.
 */
class VoiceflowWebhookController extends Controller
{
    public function leadCaptured(Request $request, Agent $agent): JsonResponse
    {
        $this->verifySecret($request, $agent);
        $this->verifyAgentAcceptingWebhooks($agent);

        $data = $request->validate([
            'voiceflow_user_id' => ['nullable', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'company' => ['nullable', 'string', 'max:255'],
            'score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'qualified' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
            'variables' => ['nullable', 'array'],
        ]);

        if (empty($data['name']) && empty($data['email'])) {
            return response()->json([
                'ok' => false,
                'error' => 'A name or email is required to capture a lead.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Match an existing lead by Voiceflow user id within THIS agent, else
        // by email within the team. Scoping the voiceflow_user_id match to
        // agent_id avoids collisions when two agents in the same team happen
        // to issue identical session ids (rare but possible).
        $lead = Lead::query()
            ->where('team_id', $agent->team_id)
            ->when($data['voiceflow_user_id'] ?? null, fn ($q, $vid) => $q->where('agent_id', $agent->id)->where('voiceflow_user_id', $vid))
            ->when(! ($data['voiceflow_user_id'] ?? null) && ($data['email'] ?? null), fn ($q) => $q->where('email', $data['email']))
            ->first();

        $attributes = array_filter([
            'name' => $data['name'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'company' => $data['company'] ?? null,
            'score' => $data['score'] ?? null,
            'notes' => $data['notes'] ?? null,
            'voiceflow_user_id' => $data['voiceflow_user_id'] ?? null,
        ], fn ($v) => $v !== null);

        $status = ($data['qualified'] ?? false) ? LeadStatus::Qualified : LeadStatus::Engaging;

        if ($lead) {
            $lead->fill($attributes);
            // Backfill agent_id on rows that pre-date multi-tenancy.
            if (! $lead->agent_id) {
                $lead->agent_id = $agent->id;
            }
            $lead->captured = array_merge($lead->captured ?? [], $data['variables'] ?? []);
            $lead->last_contacted_at = now();
            // Only advance the status forward, never regress a won/lost/assigned lead.
            if (in_array($lead->status, [LeadStatus::New, LeadStatus::Engaging], true)) {
                $lead->status = $status;
            }
            $lead->save();
        } else {
            $lead = Lead::create([
                ...$attributes,
                'team_id' => $agent->team_id,
                'agent_id' => $agent->id,
                'name' => $attributes['name'] ?? $attributes['email'],
                'source' => 'voiceflow',
                'status' => $status,
                'captured' => $data['variables'] ?? [],
                'last_contacted_at' => now(),
            ]);
        }

        // Auto-delegate freshly qualified, unassigned leads (round-robin) so a
        // rep picks them up the instant the agent qualifies them.
        if (($data['qualified'] ?? false) && ! $lead->assigned_to) {
            app(LeadDelegator::class)->assign(
                lead: $lead,
                strategy: AssignmentStrategy::RoundRobin,
            );
            $lead->refresh();
        }

        broadcast(new LeadSaved($lead));

        return response()->json(['ok' => true, 'lead_id' => $lead->id]);
    }

    /**
     * Per-agent shared secret check. Constant-time compare; uniform error
     * shape so a 401 doesn't disclose whether the secret was wrong vs. unset.
     */
    protected function verifySecret(Request $request, Agent $agent): void
    {
        $expected = (string) $agent->webhook_secret;
        $provided = (string) $request->header('X-Webhook-Secret', '');

        // The Agent model always generates a non-empty secret on create, but
        // guard explicitly so a manually-emptied row can't be exploited.
        abort_if($expected === '', Response::HTTP_SERVICE_UNAVAILABLE, 'Agent has no webhook secret configured.');
        abort_unless(hash_equals($expected, $provided), Response::HTTP_UNAUTHORIZED, 'Invalid webhook secret.');
    }

    /**
     * Disabled agents stop accepting webhook leads. Draft agents (still in
     * onboarding) DO accept — the first capture is sometimes how a user
     * realises the agent is wired up correctly.
     */
    protected function verifyAgentAcceptingWebhooks(Agent $agent): void
    {
        abort_if(
            $agent->status === Agent::STATUS_DISABLED,
            Response::HTTP_SERVICE_UNAVAILABLE,
            'Agent is disabled and not accepting captures.',
        );
    }
}
