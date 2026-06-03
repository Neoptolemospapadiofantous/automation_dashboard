<?php

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with a demo team that already has an
     * active agent + a spread of leads stamped with that agent_id. Without
     * the agent + agent_id stamping, Phase G's agent-scoped queries would
     * make the demo data invisible.
     */
    public function run(): void
    {
        $user = User::factory()->withPersonalTeam()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $agent = Agent::factory()->for($user->currentTeam)->create([
            'name' => 'Demo agent',
            'voiceflow_api_key' => 'VF.DM.demo.placeholder',
            'voiceflow_project_id' => 'demo000000000000000000aa',
        ]);
        $user->currentTeam->forceFill(['current_agent_id' => $agent->id])->save();

        Lead::factory()
            ->count(18)
            ->create([
                'team_id' => $user->currentTeam->id,
                'agent_id' => $agent->id,
            ]);
    }
}
