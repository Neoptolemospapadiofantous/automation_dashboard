<?php

namespace Tests\Security;

use App\Models\Agent;
use App\Models\Lead;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

/**
 * Category F — mass assignment.
 *
 * (a) No Eloquent model may ship fully unguarded ($guarded === []) —
 *     that turns every create()/update() call into an over-posting hole.
 * (b) The write endpoints ignore over-posted privileged fields: status
 *     and team_id can never be smuggled in alongside legitimate input.
 */
class MassAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_model_is_fully_unguarded(): void
    {
        $checked = 0;

        foreach ($this->modelClasses() as $class) {
            $model = new $class;
            $this->assertInstanceOf(Model::class, $model);

            // Pivot models (Membership, via Jetstream) inherit Laravel's
            // stock `Pivot::$guarded = []`. Pivot rows are only ever
            // written by code-driven attach()/sync() calls — never filled
            // straight from request input — so the framework default is
            // accepted here. Every NON-pivot model must stay guarded.
            if ($model instanceof Pivot) {
                continue;
            }
            $this->assertNotSame(
                [],
                $model->getGuarded(),
                "{$class} has \$guarded = [] — every column is mass-assignable.",
            );
            $checked++;
        }

        // Sanity: the glob actually found the model set (13 + 3 today).
        $this->assertGreaterThanOrEqual(15, $checked);
    }

    public function test_agents_update_ignores_overposted_status_and_team_id(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $agent = Agent::factory()->for($team)->create([
            'name' => 'Original name',
            'status' => Agent::STATUS_ACTIVE,
        ]);

        $this->actingAs($user)
            ->put(route('agents.update', $agent->slug), [
                'name' => 'x',
                'status' => 'disabled',
                'team_id' => 999,
            ])
            ->assertRedirect();

        $fresh = $agent->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('x', $fresh->name);
        $this->assertSame(Agent::STATUS_ACTIVE, $fresh->status);
        $this->assertSame($team->id, $fresh->team_id);
    }

    public function test_leads_store_stamps_team_from_current_team_not_request(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $agent = Agent::factory()->for($team)->create();
        $team->forceFill(['current_agent_id' => $agent->id])->save();

        $otherTeam = Team::factory()->create();

        $this->actingAs($user->fresh())
            ->post(route('leads.store'), [
                'name' => 'Over Poster',
                'team_id' => $otherTeam->id, // must be ignored
            ])
            ->assertRedirect();

        $lead = Lead::query()->where('name', 'Over Poster')->first();
        $this->assertInstanceOf(Lead::class, $lead);
        $this->assertSame($team->id, $lead->team_id);
        $this->assertSame($agent->id, $lead->agent_id);
        $this->assertNotSame($otherTeam->id, $lead->team_id);
    }

    /**
     * Every concrete model class under app/Models + app/Runtime/Models.
     *
     * @return list<class-string<Model>>
     */
    private function modelClasses(): array
    {
        $map = [
            app_path('Models') => 'App\\Models\\',
            app_path('Runtime/Models') => 'App\\Runtime\\Models\\',
        ];

        $classes = [];
        foreach ($map as $dir => $namespace) {
            foreach (glob($dir.'/*.php') ?: [] as $file) {
                /** @var class-string $class */
                $class = $namespace.basename($file, '.php');
                $reflection = new ReflectionClass($class);
                if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                    continue;
                }
                $classes[] = $class;
            }
        }

        return $classes;
    }
}
