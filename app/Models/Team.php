<?php

namespace App\Models;

use App\Billing\Plan;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Jetstream\Events\TeamCreated;
use Laravel\Jetstream\Events\TeamDeleted;
use Laravel\Jetstream\Events\TeamUpdated;
use Laravel\Jetstream\Team as JetstreamTeam;

class Team extends JetstreamTeam
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'personal_team',
        'current_agent_id',
        'plan',
        'credit_balance',
        'topup_balance',
        'credits_renewed_at',
        'alert_thresholds_fired',
        'stripe_customer_id',
        'stripe_subscription_id',
        'stripe_subscription_status',
        'stripe_current_period_end',
        'profile',
    ];

    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }

    public function currentAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'current_agent_id');
    }

    /**
     * Switch the team's currently-active agent. Returns true on success,
     * false if the agent doesn't belong to this team.
     */
    public function switchAgent(Agent $agent): bool
    {
        if ($agent->team_id !== $this->id) {
            return false;
        }

        $this->forceFill(['current_agent_id' => $agent->id])->save();

        return true;
    }

    /**
     * The event map for the model.
     *
     * @var array<string, class-string>
     */
    protected $dispatchesEvents = [
        'created' => TeamCreated::class,
        'updated' => TeamUpdated::class,
        'deleted' => TeamDeleted::class,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'personal_team' => 'boolean',
            'plan' => Plan::class,
            'credit_balance' => 'integer',
            'topup_balance' => 'integer',
            'credits_renewed_at' => 'datetime',
            // List of stringified percent thresholds already fired this
            // billing period — e.g. ["50","80"]. CreditBurnAlerts uses
            // this for idempotency.
            'alert_thresholds_fired' => 'array',
            'stripe_current_period_end' => 'datetime',
            // Onboarding profile (industry/use_case/team_size/etc.)
            // captured at signup. Free-form JSON; the wizard validates
            // the shape on the way in. See OnboardingController::startAgent.
            'profile' => 'array',
        ];
    }

    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    /**
     * Convenience for guards: does the team have any credits left to spend?
     * Read straight off the cached balance; the audit log is the ground truth
     * but consulting it on every interact() would be wasteful.
     */
    public function hasCredits(int $atLeast = 1): bool
    {
        // Both buckets count: the monthly allowance plus rolled-over
        // purchased top-ups (see CreditMeter for the consume order).
        return $this->totalCredits() >= $atLeast;
    }

    /**
     * Monthly allowance + purchased top-up credits combined.
     */
    public function totalCredits(): int
    {
        return (int) $this->credit_balance + (int) $this->topup_balance;
    }

    /**
     * The plan enum, defaulting to Free when the column is somehow null
     * (e.g. a team created before the billing migration that didn't run
     * the backfill). Safe default keeps the app working.
     */
    public function planObject(): Plan
    {
        return $this->plan instanceof Plan ? $this->plan : Plan::Free;
    }
}
