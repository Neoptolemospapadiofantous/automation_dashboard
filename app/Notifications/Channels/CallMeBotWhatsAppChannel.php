<?php

namespace App\Notifications\Channels;

use App\Jobs\SendEscalationWhatsApp;
use Illuminate\Notifications\Notification;

/**
 * WhatsApp alert to the founder's own number via CallMeBot's free
 * personal-use gateway — one GET, no SDK, no account beyond a one-time
 * WhatsApp opt-in message that yields the apikey. Chosen 2026-08-13
 * after Twilio's trial blocked Cyprus caller-ID verification (both SMS
 * and voice) and the founder ruled out paid options and app installs.
 *
 * Hobby-grade third-party service by design intent: this is the LOUD
 * layer; the priority-1 email leg remains the guaranteed baseline.
 * Config-gated like TwilioSmsChannel — without services.callmebot.* the
 * channel is inert, and a delivery hiccup logs a warning without
 * failing the chain.
 *
 * Notifications opt in by returning this class from via() and exposing
 * toWhatsApp(object $notifiable): string.
 */
class CallMeBotWhatsAppChannel
{
    public static function configured(): bool
    {
        return (string) config('services.callmebot.phone') !== ''
            && (string) config('services.callmebot.apikey') !== '';
    }

    /**
     * The ring burst: three messages at 0/45/90s. With a full-length
     * ringtone set on the CallMeBot contact, the phone rings, pauses,
     * and rings twice more — acoustically an incoming call. All three
     * ride the queue so the visitor's turn never waits on the gateway.
     */
    protected const BURST_DELAYS_SECONDS = [0, 45, 90];

    // @phpstan-ignore shipmonk.deadMethod (invoked dynamically by Laravel's ChannelManager when via() names this class)
    public function send(object $notifiable, Notification $notification): void
    {
        if (! self::configured() || ! method_exists($notification, 'toWhatsApp')) {
            return;
        }

        $text = (string) $notification->toWhatsApp($notifiable);
        $count = count(self::BURST_DELAYS_SECONDS);

        foreach (self::BURST_DELAYS_SECONDS as $i => $delay) {
            SendEscalationWhatsApp::dispatch($text.($i > 0 ? sprintf(' (reminder %d/%d)', $i + 1, $count) : ''))
                ->delay(now()->addSeconds($delay));
        }
    }
}
