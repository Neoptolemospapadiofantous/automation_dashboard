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
        ];
    }
}
