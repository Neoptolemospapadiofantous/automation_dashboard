<?php

namespace Tests\Integrity;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Category D (Integrity) — migrations round-trip.
 *
 * A full rollback of 40+ migrations would be slow and mostly redundant;
 * rolling back the last 3 steps and re-migrating is enough signal that
 * the most recently shipped down() methods actually work (the ones most
 * likely to be exercised in an emergency rollback).
 */
class MigrationRoundTripTest extends TestCase
{
    public function test_migrate_fresh_rollback_three_steps_and_remigrate_succeed(): void
    {
        $this->artisan('migrate:fresh')->assertSuccessful();
        $this->assertTrue(Schema::hasTable('users'));

        $this->artisan('migrate:rollback', ['--step' => 3])->assertSuccessful();

        $this->artisan('migrate')->assertSuccessful();

        // Spot-check tables touched by the latest migrations survived the trip.
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('runtime_usage'));
        $this->assertTrue(Schema::hasTable('agent_config_versions'));
    }
}
