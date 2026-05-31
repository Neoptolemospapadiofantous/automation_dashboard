<?php

namespace Database\Seeders;

use App\Models\Lead;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $user = User::factory()->withPersonalTeam()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // A spread of demo leads across the pipeline so the board isn't empty.
        Lead::factory()
            ->count(18)
            ->create(['team_id' => $user->currentTeam->id]);
    }
}
