<?php

namespace App\Http\Controllers;

use App\Events\LeadMessage;
use App\Events\LeadSaved;
use App\Models\Lead;
use App\Services\VoiceflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Server-side proxy for the Voiceflow Dialog Manager API.
 *
 * The browser never talks to Voiceflow directly — it calls these endpoints,
 * the server adds the API key, advances the conversation, captures lead fields
 * from the agent's session variables, and broadcasts updates to the team.
 */
class VoiceflowController extends Controller
{
    public function __construct(protected VoiceflowService $voiceflow)
    {
    }

    /**
     * Start (or reset) a conversation. Returns a fresh user id + the opening
     * traces. Optionally pre-fills variables (e.g. an existing lead's details).
     */
    public function launch(Request $request): JsonResponse
    {
        $this->abortIfUnconfigured();

        $data = $request->validate([
            'lead_id' => ['nullable', 'integer'],
            'variables' => ['nullable', 'array'],
        ]);

        $lead = $this->resolveLead($request, $data['lead_id'] ?? null);

        $userId = $lead?->voiceflow_user_id ?: 'web-'.Str::uuid()->toString();

        $variables = $data['variables'] ?? [];
        if ($lead) {
            $variables = array_merge(array_filter([
                'name' => $lead->name,
                'email' => $lead->email,
                'phone' => $lead->phone,
                'company' => $lead->company,
            ]), $variables);
        }

        $traces = $this->voiceflow->launch($userId, $variables);

        // Remember the Voiceflow user id on the lead for continuity.
        if ($lead && $lead->voiceflow_user_id !== $userId) {
            $lead->update(['voiceflow_user_id' => $userId]);
        }

        return $this->respond($request, $userId, $lead, $traces);
    }

    /**
     * Send a user's text reply and advance the conversation.
     */
    public function interact(Request $request): JsonResponse
    {
        $this->abortIfUnconfigured();

        $data = $request->validate([
            'user_id' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:2000'],
            'lead_id' => ['nullable', 'integer'],
        ]);

        $lead = $this->resolveLead($request, $data['lead_id'] ?? null);

        // Echo the user's message live to the team before we hit Voiceflow.
        $this->broadcastMessage($request, $lead, 'user', $data['message']);

        $traces = $this->voiceflow->sendText($data['user_id'], $data['message']);

        return $this->respond($request, $data['user_id'], $lead, $traces);
    }

    /**
     * Shared response builder: parse traces, broadcast agent messages, capture
     * lead fields from session variables, upsert the lead.
     */
    protected function respond(Request $request, string $userId, ?Lead $lead, array $traces): JsonResponse
    {
        $parsed = $this->voiceflow->parseTraces($traces);

        foreach ($parsed['messages'] as $message) {
            $this->broadcastMessage($request, $lead, 'agent', $message);
        }

        // Read the captured lead fields out of the agent's session.
        $fields = [];
        try {
            $fields = $this->voiceflow->extractLeadFields(
                $this->voiceflow->getVariables($userId)
            );
        } catch (\Throwable $e) {
            report($e);
        }

        $lead = $this->upsertLead($request, $lead, $userId, $fields);

        return response()->json([
            'user_id' => $userId,
            'lead_id' => $lead?->id,
            'messages' => $parsed['messages'],
            'buttons' => $parsed['buttons'],
            'ended' => $parsed['ended'],
            'captured' => $fields,
        ]);
    }

    /**
     * Create or update the lead from captured fields. Requires at least a name
     * or email before a lead row is created, to avoid empty placeholder leads.
     *
     * @param  array<string, mixed>  $fields
     */
    protected function upsertLead(Request $request, ?Lead $lead, string $userId, array $fields): ?Lead
    {
        $team = $request->user()->currentTeam;

        if (! $lead) {
            // Reuse an existing lead for this Voiceflow session if present.
            $lead = Lead::where('team_id', $team->id)
                ->where('voiceflow_user_id', $userId)
                ->first();
        }

        if (! $lead && empty($fields['name']) && empty($fields['email'])) {
            return null; // Nothing identifying yet.
        }

        $attributes = array_merge($fields, [
            'voiceflow_user_id' => $userId,
            'last_contacted_at' => now(),
        ]);

        if ($lead) {
            $merged = array_merge($lead->captured ?? [], $fields);
            $lead->fill($attributes);
            $lead->captured = $merged;
            if ($lead->status === \App\Enums\LeadStatus::New) {
                $lead->status = \App\Enums\LeadStatus::Engaging;
            }
            $lead->save();
        } else {
            $lead = Lead::create([
                ...$attributes,
                'team_id' => $team->id,
                'name' => $fields['name'] ?? ($fields['email'] ?? 'New contact'),
                'source' => 'voiceflow',
                'status' => \App\Enums\LeadStatus::Engaging,
                'captured' => $fields,
            ]);
        }

        broadcast(new LeadSaved($lead))->toOthers();

        return $lead;
    }

    protected function broadcastMessage(Request $request, ?Lead $lead, string $role, string $text): void
    {
        broadcast(new LeadMessage(
            teamId: $request->user()->currentTeam->id,
            leadId: $lead?->id,
            role: $role,
            text: $text,
            at: now()->toIso8601String(),
        ));
    }

    /**
     * Resolve a lead from the request, scoped to the user's current team.
     */
    protected function resolveLead(Request $request, ?int $leadId): ?Lead
    {
        if (! $leadId) {
            return null;
        }

        return Lead::where('team_id', $request->user()->currentTeam->id)
            ->find($leadId);
    }

    protected function abortIfUnconfigured(): void
    {
        abort_unless($this->voiceflow->isConfigured(), 503, 'Voiceflow is not configured.');
    }
}
