<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * One WhatsApp escalation message via CallMeBot (SHARED.md 2026-08-13
 * entry — the free personal-use gateway is the founder's phone channel).
 *
 * Queued so the visitor's chat turn never waits on a third-party HTTP
 * call, and so the "ring burst" works: the channel dispatches THREE of
 * these at 0/45/90s. Paired with a full-length ringtone on the CallMeBot
 * contact, the burst rings the founder's phone like an incoming call —
 * the requirement is that a lead asking for a human is never missed.
 */
class SendEscalationWhatsApp implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /** Alerts must not retry-storm a hobby gateway; one attempt per ring. */
    // @phpstan-ignore shipmonk.deadProperty.neverRead (read by the queue worker, not app code)
    public int $tries = 1;

    public function __construct(public string $text) {}

    public function handle(): void
    {
        $phone = (string) config('services.callmebot.phone');
        $apikey = (string) config('services.callmebot.apikey');
        if ($phone === '' || $apikey === '') {
            return; // channel un-configured between dispatch and run — drop quietly
        }

        try {
            $response = Http::timeout(15)->get('https://api.callmebot.com/whatsapp.php', [
                'phone' => $phone,
                'apikey' => $apikey,
                'text' => $this->text,
            ]);

            if ($response->failed()) {
                Log::warning('CallMeBot WhatsApp alert failed', ['status' => $response->status()]);
            }
        } catch (\Throwable $e) {
            Log::warning('CallMeBot WhatsApp alert unreachable: '.$e->getMessage());
        }
    }
}
