<?php

namespace App\Http\Controllers;

use App\Enums\AssignmentStrategy;
use App\Enums\LeadStatus;
use App\Events\LeadSaved;
use App\Models\Lead;
use App\Models\Team;
use App\Services\LeadDelegator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inbound webhook for Voiceflow Custom Actions.
 *
 * A Voiceflow agent can POST a qualified lead here the instant it's captured,
 * rather than us polling session variables. Secured by a shared secret because
 * Voiceflow calls it directly (no user session). Configure the agent to send
 * the X-Webhook-Secret header matching VOICEFLOW_WEBHOOK_SECRET.
 */
class VoiceflowWebhookController extends Controller
{
    public function leadCaptured(Request $request): JsonResponse
    {
        $this->verifySecret($request);

        $data = $request->validate([
            'team_id' => ['required', 'integer', 'exists:teams,id'],
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

        $team = Team::findOrFail($data['team_id']);

        // Match an existing lead by Voiceflow user id, else by email.
        $lead = Lead::where('team_id', $team->id)
            ->when($data['voiceflow_user_id'] ?? null, fn ($q, $vid) => $q->where('voiceflow_user_id', $vid))
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
                'team_id' => $team->id,
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

    protected function verifySecret(Request $request): void
    {
        $expected = config('services.voiceflow.webhook_secret');

        abort_if(empty($expected), Response::HTTP_SERVICE_UNAVAILABLE, 'Webhook secret not configured.');

        $provided = $request->header('X-Webhook-Secret', '');

        abort_unless(hash_equals($expected, $provided), Response::HTTP_UNAUTHORIZED, 'Invalid webhook secret.');
    }
}
