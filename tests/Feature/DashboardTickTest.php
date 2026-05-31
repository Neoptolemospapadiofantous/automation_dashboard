<?php

namespace Tests\Feature;

use App\Events\DashboardTick;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Jetstream\Jetstream;
use Tests\TestCase;

class DashboardTickTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_fire_a_tick(): void
    {
        $this->post('/dashboard/tick')->assertRedirect('/login');
    }

    public function test_authenticated_user_can_fire_a_tick_and_it_broadcasts(): void
    {
        Event::fake([DashboardTick::class]);

        $user = User::factory()->withPersonalTeam()->create();

        $response = $this->actingAs($user)->postJson('/dashboard/tick');

        $response->assertOk()->assertJson(['ok' => true]);
        $response->assertJsonStructure(['ok', 'count', 'at']);

        Event::assertDispatched(DashboardTick::class, function (DashboardTick $event) {
            return $event->count >= 1
                && $event->broadcastOn()->name === 'dashboard'
                && $event->broadcastAs() === 'tick';
        });
    }
}
