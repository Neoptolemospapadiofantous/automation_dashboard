<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * A user's notification preferences with the defaults applied.
 *
 * Shape (users.notification_preferences, JSON):
 *   {
 *     "events": {
 *       "handoff":       {"bell": true, "mail": true, "call": true},
 *       "lead_captured": {"bell": true, "mail": true},
 *       "lead_assigned": {"bell": true, "mail": true},
 *       "follow_up":     {"bell": true, "mail": true},
 *       "credits":       {"bell": true, "mail": true},
 *       "weekly_digest": {"mail": true}
 *     },
 *     "quiet_hours": {"enabled": false, "start": "22:00", "end": "08:00", "timezone": "Asia/Nicosia"}
 *   }
 *
 * The defaults are exactly the behaviour before preferences existed, so a
 * user who never opens the page notices nothing. Quiet hours silence the
 * INTERRUPTING channels (mail, call); the bell always records the event.
 */
final class NotificationPreferences
{
    /** Event keys and the channels each can use, in display order. */
    public const EVENTS = [
        'handoff' => ['bell', 'mail', 'call'],
        'lead_captured' => ['bell', 'mail'],
        'lead_assigned' => ['bell', 'mail'],
        'follow_up' => ['bell', 'mail'],
        'credits' => ['bell', 'mail'],
        'weekly_digest' => ['mail'],
    ];

    /** The Laravel channel name behind each toggle. */
    private const CHANNEL_MAP = ['bell' => 'database', 'mail' => 'mail'];

    /**
     * @param  array<string, array<string, bool>>  $events
     * @param  array{enabled: bool, start: string, end: string, timezone: string}  $quietHours
     */
    private function __construct(
        public readonly array $events,
        public readonly array $quietHours,
    ) {}

    /**
     * @param  mixed  $raw  the stored JSON (or null for a user who never set anything)
     */
    public static function fromArray(mixed $raw): self
    {
        $raw = is_array($raw) ? $raw : [];
        $rawEvents = is_array($raw['events'] ?? null) ? $raw['events'] : [];

        $events = [];
        foreach (self::EVENTS as $event => $channels) {
            foreach ($channels as $channel) {
                $stored = $rawEvents[$event][$channel] ?? null;
                $events[$event][$channel] = is_bool($stored) ? $stored : true;
            }
        }

        $quiet = is_array($raw['quiet_hours'] ?? null) ? $raw['quiet_hours'] : [];
        $timezone = is_string($quiet['timezone'] ?? null) && in_array($quiet['timezone'], timezone_identifiers_list(), true)
            ? $quiet['timezone']
            : 'Asia/Nicosia';

        return new self($events, [
            'enabled' => (bool) ($quiet['enabled'] ?? false),
            'start' => self::time($quiet['start'] ?? null, '22:00'),
            'end' => self::time($quiet['end'] ?? null, '08:00'),
            'timezone' => $timezone,
        ]);
    }

    /**
     * Whether a given toggle is on. Unknown event/channel = on, so a new
     * notification type is never silently muted by an old preference blob.
     */
    public function wants(string $event, string $channel): bool
    {
        return $this->events[$event][$channel] ?? true;
    }

    /**
     * Filter a notification's channel list by this user's preferences.
     * Quiet hours drop mail and the call; the bell is never quiet.
     *
     * @param  list<string>  $channels  Laravel channel names or channel classes
     * @return list<string>
     */
    public function filter(string $event, array $channels, ?Carbon $now = null): array
    {
        $quiet = $this->isQuiet($now);
        $keep = [];
        foreach ($channels as $channel) {
            $toggle = array_search($channel, self::CHANNEL_MAP, true);
            if ($toggle === false) {
                // A class-based channel (the phone call) maps to the 'call' toggle.
                $toggle = 'call';
            }
            if (! $this->wants($event, $toggle)) {
                continue;
            }
            if ($quiet && $toggle !== 'bell') {
                continue;
            }
            $keep[] = $channel;
        }

        return $keep;
    }

    /**
     * Inside the quiet window right now? Windows may cross midnight
     * (22:00 → 08:00), which is the common case.
     */
    public function isQuiet(?Carbon $now = null): bool
    {
        if (! $this->quietHours['enabled']) {
            return false;
        }
        $local = ($now ?? Carbon::now())->copy()->setTimezone($this->quietHours['timezone']);
        $minutes = $local->hour * 60 + $local->minute;
        $start = self::minutes($this->quietHours['start']);
        $end = self::minutes($this->quietHours['end']);

        if ($start === $end) {
            return false;
        }

        return $start < $end
            ? ($minutes >= $start && $minutes < $end)
            : ($minutes >= $start || $minutes < $end);
    }

    /**
     * @return array{events: array<string, array<string, bool>>, quiet_hours: array{enabled: bool, start: string, end: string, timezone: string}}
     */
    public function toArray(): array
    {
        return ['events' => $this->events, 'quiet_hours' => $this->quietHours];
    }

    private static function time(mixed $value, string $default): string
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1 ? $value : $default;
    }

    private static function minutes(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));

        return $h * 60 + $m;
    }
}
