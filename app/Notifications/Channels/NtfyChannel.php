<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Mobile push via ntfy (ntfy.sh or self-hosted) — one HTTP POST, no
 * accounts, no SMS provider: the phone runs the ntfy app subscribed to a
 * secret topic, and anything POSTed to that topic pops as a push
 * notification within seconds. Priority "urgent" makes escalations
 * pierce a pocket.
 *
 * Config-gated like TwilioSmsChannel: without services.ntfy.topic the
 * channel is inert, and a delivery hiccup logs a warning without failing
 * the chain — the bell + email legs must always land. The topic name is
 * the only secret (treat it like a token; rotate by picking a new one).
 *
 * Notifications opt in by returning this class from via() and exposing
 * toNtfy(object $notifiable): array{title: string, body: string, click: string}.
 */
class NtfyChannel
{
    public static function configured(): bool
    {
        return (string) config('services.ntfy.topic') !== '';
    }

    // @phpstan-ignore shipmonk.deadMethod (invoked dynamically by Laravel's ChannelManager when via() names this class)
    public function send(object $notifiable, Notification $notification): void
    {
        if (! self::configured() || ! method_exists($notification, 'toNtfy')) {
            return;
        }

        /** @var array{title: string, body: string, click: string} $push */
        $push = $notification->toNtfy($notifiable);

        $server = rtrim((string) config('services.ntfy.server', 'https://ntfy.sh'), '/');
        $topic = (string) config('services.ntfy.topic');

        try {
            $request = Http::timeout(10)->withHeaders(array_filter([
                'Title' => $push['title'],
                'Priority' => 'urgent',
                'Tags' => 'raising_hand',
                'Click' => $push['click'],
                'Authorization' => (string) config('services.ntfy.token') !== ''
                    ? 'Bearer '.(string) config('services.ntfy.token')
                    : null,
            ]));

            $response = $request->withBody($push['body'], 'text/plain')->post($server.'/'.$topic);

            if ($response->failed()) {
                Log::warning('ntfy push failed', ['status' => $response->status()]);
            }
        } catch (\Throwable $e) {
            Log::warning('ntfy push unreachable: '.$e->getMessage());
        }
    }
}
