<?php

namespace App\Listeners;

use App\Models\User;
use App\Notifications\WelcomeEmail;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Sends the welcome email after a user registers. Queued so the
 * registration response isn't gated on SMTP latency.
 *
 * Laravel 11+ auto-discovers listeners by method signature — the
 * `handle(Registered $event)` shape is enough.
 */
class SendWelcomeEmail implements ShouldQueue
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
