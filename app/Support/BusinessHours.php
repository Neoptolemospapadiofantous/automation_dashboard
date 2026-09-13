<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * When a team is reachable for a human handoff.
 *
 * Shape (teams.business_hours, JSON):
 *   {
 *     "enabled": true,
 *     "timezone": "Asia/Nicosia",
 *     "days": {"mon": ["09:00","18:00"], "tue": [...], ..., "sun": null},
 *     "away_message": "We're closed right now — leave your email and a teammate will reply when we open."
 *   }
 *
 * A null column or enabled:false means always open, which is the behaviour
 * before hours existed. Outside hours an escalation still lands in the
 * queue with a bell and an email, but the phone does not ring and the
 * visitor is told when to expect a reply. This is also the hook where an
 * automated voice agent will take the out-of-hours handoff once one
 * exists: EscalateToHuman marks the conversation `handoff_out_of_hours`.
 */
final class BusinessHours
{
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public const DEFAULT_AWAY = "We're closed right now. Leave your email or phone and a teammate will reply when we open.";

    /**
     * @param  array<string, array{0: string, 1: string}|null>  $days
     */
    private function __construct(
        public readonly bool $enabled,
        public readonly string $timezone,
        public readonly array $days,
        public readonly string $awayMessage,
    ) {}

    public static function fromArray(mixed $raw): self
    {
        $raw = is_array($raw) ? $raw : [];
        $timezone = is_string($raw['timezone'] ?? null) && in_array($raw['timezone'], timezone_identifiers_list(), true)
            ? $raw['timezone']
            : 'Asia/Nicosia';

        $rawDays = is_array($raw['days'] ?? null) ? $raw['days'] : [];
        $days = [];
        foreach (self::DAYS as $day) {
            // An explicit null is "closed that day"; only an ABSENT key takes
            // the weekday default, so a saved schedule round-trips exactly.
            $window = array_key_exists($day, $rawDays)
                ? $rawDays[$day]
                : (in_array($day, ['sat', 'sun'], true) ? null : ['09:00', '18:00']);
            $days[$day] = is_array($window)
                && isset($window[0], $window[1])
                && self::validTime($window[0])
                && self::validTime($window[1])
                && $window[0] < $window[1]
                ? [(string) $window[0], (string) $window[1]]
                : null;
        }

        $away = is_string($raw['away_message'] ?? null) ? trim($raw['away_message']) : '';

        return new self(
            enabled: (bool) ($raw['enabled'] ?? false),
            timezone: $timezone,
            days: $days,
            awayMessage: $away !== '' ? mb_substr($away, 0, 300) : self::DEFAULT_AWAY,
        );
    }

    /**
     * Open right now? Always true while hours are disabled.
     */
    public function isOpen(?Carbon $now = null): bool
    {
        if (! $this->enabled) {
            return true;
        }
        $local = ($now ?? Carbon::now())->copy()->setTimezone($this->timezone);
        $window = $this->days[strtolower($local->format('D'))] ?? null;
        if ($window === null) {
            return false;
        }
        $hhmm = $local->format('H:i');

        return $hhmm >= $window[0] && $hhmm < $window[1];
    }

    /**
     * The next opening, for the visitor-facing away line ("we open Monday
     * at 09:00"). Null when no day has a window.
     */
    public function nextOpening(?Carbon $now = null): ?Carbon
    {
        $local = ($now ?? Carbon::now())->copy()->setTimezone($this->timezone);
        for ($i = 0; $i < 8; $i++) {
            $day = $local->copy()->addDays($i);
            $window = $this->days[strtolower($day->format('D'))] ?? null;
            if ($window === null) {
                continue;
            }
            $opens = $day->copy()->setTimeFromTimeString($window[0]);
            if ($opens->greaterThan($local)) {
                return $opens;
            }
        }

        return null;
    }

    /**
     * The deterministic line appended to an out-of-hours escalation reply.
     */
    public function awayLine(?Carbon $now = null): string
    {
        $line = $this->awayMessage;
        $next = $this->nextOpening($now);
        if ($next !== null) {
            $line .= ' We open '.$next->format('l').' at '.$next->format('H:i').'.';
        }

        return $line;
    }

    /**
     * @return array{enabled: bool, timezone: string, days: array<string, array{0: string, 1: string}|null>, away_message: string}
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'timezone' => $this->timezone,
            'days' => $this->days,
            'away_message' => $this->awayMessage,
        ];
    }

    private static function validTime(mixed $value): bool
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }
}
