<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\WelcomeEmail;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WelcomeEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_welcome_email_fires_on_registered_event(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        event(new Registered($user));

        Notification::assertSentTo($user, WelcomeEmail::class);
    }
}
