<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

    // @phpstan-ignore shipmonk.deadMethod (invoked dynamically by Laravel's ChannelManager when via() names this class)
    public function send(object $notifiable, Notification $notification): void
    {
        if (! self::configured() || ! method_exists($notification, 'toWhatsApp')) {
            return;
        }

        try {
            $response = Http::timeout(15)->get('https://api.callmebot.com/whatsapp.php', [
                'phone' => (string) config('services.callmebot.phone'),
                'apikey' => (string) config('services.callmebot.apikey'),
                'text' => (string) $notification->toWhatsApp($notifiable),
            ]);

            if ($response->failed()) {
                Log::warning('CallMeBot WhatsApp alert failed', ['status' => $response->status()]);
            }
        } catch (\Throwable $e) {
            Log::warning('CallMeBot WhatsApp alert unreachable: '.$e->getMessage());
        }
    }
}
