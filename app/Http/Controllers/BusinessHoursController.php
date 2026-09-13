<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesByTeamRole;
use App\Models\Team;
use App\Support\BusinessHours;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Business hours. Team-level: when a visitor asks for a human
 * outside these hours the escalation still lands (bell + email), the phone
 * stays quiet, and the visitor is told when to expect a reply. Off by
 * default so nothing changes for a team that never opens the page.
 */
class BusinessHoursController extends Controller
{
    use AuthorizesByTeamRole;

    public function index(Request $request): Response
    {
        $team = $this->team($request);
        $hours = $team->businessHours();

        return Inertia::render('Settings/Hours', [
            'hours' => $hours->toArray(),
            'openNow' => $hours->isOpen(),
            'nextOpening' => $hours->nextOpening()?->toIso8601String(),
            'defaultAway' => BusinessHours::DEFAULT_AWAY,
            'days' => BusinessHours::DAYS,
            'timezones' => \DateTimeZone::listIdentifiers(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $team = $this->team($request);
        $this->requireOwner($request, 'change business hours');

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'timezone' => ['required', 'string', 'timezone:all'],
            'days' => ['required', 'array'],
            'days.*' => ['nullable', 'array', 'size:2'],
            'days.*.0' => ['required_with:days.*', 'date_format:H:i'],
            'days.*.1' => ['required_with:days.*', 'date_format:H:i'],
            'away_message' => ['nullable', 'string', 'max:300'],
        ]);

        foreach (BusinessHours::DAYS as $day) {
            $window = $data['days'][$day] ?? null;
            if (is_array($window) && $window[0] >= $window[1]) {
                return back()->withErrors(['days' => ucfirst($day).': closing time must be after opening time.']);
            }
        }

        $team->forceFill(['business_hours' => BusinessHours::fromArray($data)->toArray()])->save();

        return back()->with('flash', ['banner' => 'Business hours saved.']);
    }

    private function team(Request $request): Team
    {
        $team = $request->user()->currentTeam;
        abort_unless($team instanceof Team, 403, 'Sign in to a team first.');

        return $team;
    }
}
