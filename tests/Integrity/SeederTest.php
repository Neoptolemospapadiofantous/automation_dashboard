<?php

namespace Tests\Integrity;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Category D (Integrity) — seeders.
 *
 * DatabaseSeeder broke recently (agent-less demo data invisible to the
 * agent-scoped queries); this pins that `db:seed` runs cleanly and
 * produces the demo fixture the docs promise.
 */
class SeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_runs_cleanly(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('users', ['email' => 'test@example.com']);
        $this->assertDatabaseHas('agents', ['name' => 'Demo agent']);
        $this->assertDatabaseCount('leads', 18);

        // The demo team must point at its agent, and every lead must be
        // stamped with it — otherwise agent-scoped queries hide the data.
        $this->assertDatabaseMissing('teams', ['current_agent_id' => null]);
        $this->assertDatabaseMissing('leads', ['agent_id' => null]);
    }
}
