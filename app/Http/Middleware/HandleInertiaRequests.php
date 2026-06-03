<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            // Unread lead notifications for the bell UI.
            'notifications' => fn () => $request->user()
                ? $request->user()->unreadNotifications()->latest()->take(10)->get()
                    ->map(fn ($n) => [
                        'id' => $n->id,
                        'lead_id' => $n->data['lead_id'] ?? null,
                        'message' => $n->data['message'] ?? 'Notification',
                        'created_at' => $n->created_at->toIso8601String(),
                    ])->values()
                : [],

            // Agent picker data for the nav. Shared on every Inertia request
            // so the picker stays in sync without each page re-querying.
            'currentAgent' => fn () => $request->user()?->currentTeam?->currentAgent
                ? [
                    'id' => $request->user()->currentTeam->currentAgent->id,
                    'name' => $request->user()->currentTeam->currentAgent->name,
                    'status' => $request->user()->currentTeam->currentAgent->status,
                ]
                : null,
            'teamAgents' => fn () => $request->user()?->currentTeam
                ? $request->user()->currentTeam->agents()->orderBy('created_at')->get()
                    ->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'status' => $a->status])
                    ->values()
                : [],

            // Billing snapshot for the credit pill in the sidebar. Cheap —
            // already cached on the team row, no extra query. Allows the
            // UI to render "234 / 5000 credits" without each page asking.
            'billing' => fn () => $request->user()?->currentTeam
                ? [
                    'plan' => $request->user()->currentTeam->planObject()->value,
                    'plan_label' => $request->user()->currentTeam->planObject()->label(),
                    'credits_used' => max(0, $request->user()->currentTeam->planObject()->monthlyCredits() - $request->user()->currentTeam->credit_balance),
                    'credits_total' => $request->user()->currentTeam->planObject()->monthlyCredits(),
                    'credits_remaining' => $request->user()->currentTeam->credit_balance,
                    'max_agents' => $request->user()->currentTeam->planObject()->maxAgents(),
                    'agents_count' => $request->user()->currentTeam->agents()->count(),
                    'allows_topups' => $request->user()->currentTeam->planObject()->allowsTopUps(),
                ]
                : null,
        ];
    }
}
