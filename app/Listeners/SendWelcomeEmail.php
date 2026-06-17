<?php

namespace App\Listeners;

use App\Models\User;
use App\Notifications\WelcomeEmail;
use Illuminate\Auth\Events\Registered;

/**
 * Sends the welcome email after a user registers.
 *
 * The listener itself runs synchronously, but WelcomeEmail is a queued
 * (ShouldQueue) notification on the "mail" queue, so the only in-request
 * work is enqueuing the job — the registration response is never gated on
 * mail-provider latency.
 *
 * Laravel 11+ auto-discovers listeners by method signature — the
 * `handle(Registered $event)` shape is enough.
 */
class SendWelcomeEmail
{
    public function handle(Registered $event): void
    {
        $user = $event->user;
        if (! $user instanceof User) {
            return;
        }

        $user->notify(new WelcomeEmail);
    }
}
