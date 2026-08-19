<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Place one escalation voice call via CallMeBot's free Telegram Call API
 * (SHARED.md 2026-08-19 — the founder's chosen phone channel: a call is
 * the one alert that interrupts everything). The founder's phone RINGS
 * as a real Telegram call; on answer, Google TTS reads the alert twice.
 * cc=yes also drops a text copy of the alert in their Telegram, so a
 * missed call still leaves the details.
 *
 * Queued so the visitor's chat turn never waits on the gateway; one
 * attempt (tries=1) — no retry storms against a hobby service, and the
 * bell + priority-1 email legs always land regardless.
 */
class PlaceEscalationCall implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    // @phpstan-ignore shipmonk.deadProperty.neverRead (read by the queue worker, not app code)
    public int $tries = 1;

    public function __construct(public string $text) {}

    public function handle(): void
    {
        $user = (string) config('services.callmebot.telegram_user');
        if ($user === '') {
            return; // channel un-configured between dispatch and run — drop quietly
        }

        try {
            $response = Http::timeout(20)->get('https://api.callmebot.com/start.php', [
                'user' => $user,
                // TTS is capped at 256 chars — the notification builds a short line.
                'text' => mb_substr($this->text, 0, 256),
                'lang' => 'en-GB-Standard-B',
                'rpt' => 2,
                'cc' => 'yes',
            ]);

            if ($response->failed()) {
                Log::warning('CallMeBot escalation call failed', ['status' => $response->status()]);
            }
        } catch (\Throwable $e) {
            Log::warning('CallMeBot escalation call unreachable: '.$e->getMessage());
        }
    }
}
