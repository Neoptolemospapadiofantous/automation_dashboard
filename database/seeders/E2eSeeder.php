<?php

namespace Database\Seeders;

use App\Models\Agent;
use Illuminate\Database\Seeder;

/**
 * Seeds a single ACTIVE agent with a fixed slug for the Playwright e2e layer,
 * so the browser smokes have a deterministic target (the embed surface keys on
 * slug). CI's e2e job runs this, then exports the slug as E2E_AGENT_SLUG.
 *
 * Idempotent: a re-run reuses the existing agent rather than colliding on slug.
 */
class E2eSeeder extends Seeder
{
    public const SLUG = 'e2e-smoke-agent';

    public function run(): void
    {
        if (Agent::where('slug', self::SLUG)->exists()) {
            return;
        }

        Agent::factory()->create([
            'slug' => self::SLUG,
            'name' => 'E2E Smoke Agent',
        ]);
    }
}
