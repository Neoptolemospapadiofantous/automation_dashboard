<?php

namespace App\Http\Controllers;

use App\Support\NotificationPreferences;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Notifications. Per USER, not per team: the owner and a rep
 * on the same team want different things in their inbox. What is saved is
 * only the deviation from the defaults' shape, normalised through
 * NotificationPreferences so a hand-edited blob can never mute an event
 * the code does not know about.
 */
class NotificationPreferencesController extends Controller
{
    public function index(Request $request): Response
    {
        $prefs = $request->user()->notificationPreferences();

        return Inertia::render('Settings/Notifications', [
            'preferences' => $prefs->toArray(),
            'events' => [
                ['key' => 'handoff', 'label' => 'A visitor asks for a human', 'hint' => 'The one alert that cannot wait. The call rings your phone via Telegram when configured.', 'channels' => ['bell', 'mail', 'call']],
                ['key' => 'lead_captured', 'label' => 'A new lead is captured', 'hint' => 'Owner only — the moment the chat saves a name and a way to reply.', 'channels' => ['bell', 'mail']],
                ['key' => 'lead_assigned', 'label' => 'A lead is assigned to me', 'hint' => 'Sent to the rep who receives the lead.', 'channels' => ['bell', 'mail']],
                ['key' => 'follow_up', 'label' => 'A lead is waiting on first contact', 'hint' => 'One nudge per lead when nobody has reached out in time.', 'channels' => ['bell', 'mail']],
                ['key' => 'credits', 'label' => 'Credits running low or out', 'hint' => 'At 50 %, 80 % and 100 % of the monthly allowance.', 'channels' => ['bell', 'mail']],
                ['key' => 'weekly_digest', 'label' => 'The Monday week-in-review', 'hint' => 'Skipped automatically on a quiet week.', 'channels' => ['mail']],
            ],
            'timezones' => \DateTimeZone::listIdentifiers(),
            'callConfigured' => (string) config('services.callmebot.telegram_user') !== '',
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'events' => ['required', 'array'],
            'events.*' => ['array'],
            'events.*.*' => ['boolean'],
            'quiet_hours' => ['required', 'array'],
            'quiet_hours.enabled' => ['required', 'boolean'],
            'quiet_hours.start' => ['required', 'date_format:H:i'],
            'quiet_hours.end' => ['required', 'date_format:H:i'],
            'quiet_hours.timezone' => ['required', 'string', 'timezone:all'],
        ]);

        $normalised = NotificationPreferences::fromArray($data)->toArray();

        $request->user()->forceFill(['notification_preferences' => $normalised])->save();

        return back()->with('flash', ['banner' => 'Notification preferences saved.']);
    }
}
