<?php

namespace App\Http\Controllers;

use App\Enums\LeadStatus;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Team-scoped analytics overview. Numbers are computed live; the page also
     * subscribes to broadcasts so counters tick without a reload.
     */
    public function __invoke(Request $request): Response
    {
        $teamId = $request->user()->currentTeam->id;

        return Inertia::render('Dashboard', $this->stats($teamId));
    }

    /**
     * @return array<string, mixed>
     */
    protected function stats(int $teamId): array
    {
        $byStatus = Lead::where('team_id', $teamId)
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $totalLeads = (int) $byStatus->sum();
        $won = (int) ($byStatus[LeadStatus::Won->value] ?? 0);
        $lost = (int) ($byStatus[LeadStatus::Lost->value] ?? 0);
        $qualified = (int) ($byStatus[LeadStatus::Qualified->value] ?? 0);
        $assigned = (int) ($byStatus[LeadStatus::Assigned->value] ?? 0);

        $decided = $won + $lost;
        $conversionRate = $decided > 0 ? round(($won / $decided) * 100, 1) : 0.0;

        // Funnel in pipeline order.
        $funnel = collect(LeadStatus::board())->map(fn ($s) => [
            'label' => $s['label'],
            'value' => $s['value'],
            'color' => $s['color'],
            'count' => (int) ($byStatus[$s['value']] ?? 0),
        ])->values();

        // Per-rep open load (assigned + qualified).
        $repLoad = Lead::where('team_id', $teamId)
            ->whereIn('status', [LeadStatus::Assigned->value, LeadStatus::Qualified->value])
            ->whereNotNull('assigned_to')
            ->with('assignee:id,name')
            ->get()
            ->groupBy('assigned_to')
            ->map(fn ($rows) => [
                'name' => $rows->first()->assignee?->name ?? 'Unknown',
                'count' => $rows->count(),
            ])
            ->sortByDesc('count')
            ->values();

        return [
            'stats' => [
                'total_leads' => $totalLeads,
                'qualified' => $qualified,
                'assigned' => $assigned,
                'won' => $won,
                'lost' => $lost,
                'conversion_rate' => $conversionRate,
                'conversations' => Conversation::where('team_id', $teamId)->count(),
                'messages' => Message::where('team_id', $teamId)->count(),
                'active_conversations' => Conversation::where('team_id', $teamId)->where('status', 'active')->count(),
            ],
            'funnel' => $funnel,
            'rep_load' => $repLoad,
        ];
    }
}
