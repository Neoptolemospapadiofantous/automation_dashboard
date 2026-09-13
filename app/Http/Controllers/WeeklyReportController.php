<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesByTeamRole;
use App\Models\Team;
use App\Support\WeeklyReport;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The shareable weekly report.
 *
 *   GET  /settings/report          — the owner's control: link on/off, rotate
 *   POST /settings/report/enable   — mint a token (sharing on)
 *   POST /settings/report/rotate   — new token, old link dies
 *   POST /settings/report/disable  — token null, link dies
 *   GET  /report/{token}           — PUBLIC, no auth: last 7 full days, live
 *
 * The public page is a Blade view, not Inertia: it is read by people who
 * have no account, on a link forwarded in an email, so it must not need
 * the app shell. Numbers come from WeeklyReport — the same block the
 * Monday email carries.
 */
class WeeklyReportController extends Controller
{
    use AuthorizesByTeamRole;

    public function settings(Request $request, WeeklyReport $report): Response
    {
        $team = $this->team($request);

        return Inertia::render('Settings/Report', [
            'shareUrl' => $team->report_token !== null ? route('report.public', $team->report_token) : null,
            'preview' => $report->stats($team),
        ]);
    }

    public function enable(Request $request): RedirectResponse
    {
        $team = $this->team($request);
        $this->requireOwner($request, 'share the weekly report');

        if ($team->report_token === null) {
            $team->forceFill(['report_token' => WeeklyReport::newToken()])->save();
        }

        return back()->with('flash', ['banner' => 'Sharing is on. Anyone with the link can read the report.']);
    }

    public function rotate(Request $request): RedirectResponse
    {
        $team = $this->team($request);
        $this->requireOwner($request, 'share the weekly report');

        $team->forceFill(['report_token' => WeeklyReport::newToken()])->save();

        return back()->with('flash', ['banner' => 'New link created. The old one no longer works.']);
    }

    public function disable(Request $request): RedirectResponse
    {
        $team = $this->team($request);
        $this->requireOwner($request, 'share the weekly report');

        $team->forceFill(['report_token' => null])->save();

        return back()->with('flash', ['banner' => 'Sharing is off. The link no longer works.']);
    }

    public function show(string $token, WeeklyReport $report): View
    {
        $team = Team::query()->where('report_token', $token)->first();
        abort_if($team === null, 404);

        return view('report.weekly', [
            'stats' => $report->stats($team),
            'generatedAt' => now(),
        ]);
    }

    private function team(Request $request): Team
    {
        $team = $request->user()->currentTeam;
        abort_unless($team instanceof Team, 403, 'Sign in to a team first.');

        return $team;
    }
}
