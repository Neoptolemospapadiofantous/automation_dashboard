<?php

namespace App\Notifications\Channels;

use App\Jobs\PlaceEscalationCall;
use Illuminate\Notifications\Notification;

/**
 * Escalation VOICE CALL to the founder's phone via CallMeBot's free
 * Telegram Call API — the phone rings as a real call and a synthesized
 * voice reads the alert (see PlaceEscalationCall for the mechanics and
 * SHARED.md 2026-08-19 for the channel history: Slack, ntfy, Twilio SMS
 * and WhatsApp were each built and replaced on founder direction; the
 * call is the accepted end state).
 *
 * Config-gated: without services.callmebot.telegram_user the channel is
 * inert, and the bell + priority-1 email legs must always land — a call
 * failure only ever logs a warning.
 *
 * Notifications opt in by returning this class from via() and exposing
 * toCallText(object $notifiable): string (kept ≤256 chars for TTS).
 */
class CallMeBotTelegramCallChannel
{
    public static function configured(): bool
    {
        return (string) config('services.callmebot.telegram_user') !== '';
    }

    // @phpstan-ignore shipmonk.deadMethod (invoked dynamically by Laravel's ChannelManager when via() names this class)
    public function send(object $notifiable, Notification $notification): void
    {
        if (! self::configured() || ! method_exists($notification, 'toCallText')) {
            return;
        }

        PlaceEscalationCall::dispatch((string) $notification->toCallText($notifiable));
    }
}
