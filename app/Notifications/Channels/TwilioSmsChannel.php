<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Minimal Twilio SMS channel — one HTTP POST, no SDK. Config-gated like
 * the BI emitter: with services.twilio unset the channel is inert, and a
 * provider hiccup logs a warning without failing the notification chain
 * (the bell + email legs must always land).
 *
 * Notifications opt in by returning this class from via() and exposing
 * toSms(object $notifiable): string. The recipient is the notifiable's
 * notification_phone (E.164).
 */
class TwilioSmsChannel
{
    public static function configured(): bool
    {
        return (string) config('services.twilio.sid') !== ''
            && (string) config('services.twilio.token') !== ''
            && (string) config('services.twilio.from') !== '';
    }

    // @phpstan-ignore shipmonk.deadMethod (invoked dynamically by Laravel's ChannelManager when via() names this class)
    public function send(object $notifiable, Notification $notification): void
    {
        $to = trim((string) ($notifiable->notification_phone ?? ''));
        if ($to === '' || ! self::configured() || ! method_exists($notification, 'toSms')) {
            return;
        }

        $sid = (string) config('services.twilio.sid');

        try {
            $response = Http::asForm()
                ->withBasicAuth($sid, (string) config('services.twilio.token'))
                ->timeout(10)
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                    'From' => (string) config('services.twilio.from'),
                    'To' => $to,
                    'Body' => (string) $notification->toSms($notifiable),
                ]);

            if ($response->failed()) {
                Log::warning('Twilio SMS failed', [
                    'status' => $response->status(),
                    'error' => (string) $response->json('message', $response->body()),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Twilio SMS unreachable: '.$e->getMessage());
        }
    }
}
