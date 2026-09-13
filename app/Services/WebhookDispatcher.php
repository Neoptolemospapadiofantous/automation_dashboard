<?php

namespace App\Services;

use App\Jobs\DeliverWebhook;
use App\Models\Team;
use App\Models\TeamWebhook;
use Illuminate\Support\Str;

/**
 * Fan an event out to every active webhook on a team that subscribed to
 * it. Each delivery is its own queued job, so one slow endpoint never
 * delays another and a customer's dead URL cannot slow a visitor's turn.
 *
 * Always best-effort: callers wrap in rescue() because a webhook is a
 * side effect of the product, never a precondition for it.
 */
class WebhookDispatcher
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(Team $team, string $event, array $payload): void
    {
        $hooks = TeamWebhook::query()
            ->where('team_id', $team->id)
            ->where('active', true)
            ->get()
            ->filter(fn (TeamWebhook $hook) => $hook->listensTo($event));

        if ($hooks->isEmpty()) {
            return;
        }

        $body = [
            'id' => (string) Str::ulid(),
            'event' => $event,
            'created_at' => now()->toIso8601String(),
            'team_id' => $team->id,
            'data' => $payload,
        ];

        foreach ($hooks as $hook) {
            DeliverWebhook::dispatch($hook->id, $body);
        }
    }
}
