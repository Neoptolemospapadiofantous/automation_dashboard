<?php

namespace App\Models;

use App\Billing\CreditMeter;
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

    protected static function booted(): void
    {
        // Hand a new team its opening credit allotment.
        //
        // FREE tiers are granted immediately, in every environment: since the
        // 2026-08-27 repricing the free rung is an entitlement, not an unpaid
        // invoice, and there is nothing to wait for. Before this, a fresh
        // signup sat at 0 credits — its agent unable to answer a single
        // message — until the nightly credits:grant-renewals ran, i.e. for up
        // to 24 hours. That silently defeated the whole point of the free tier.
        //
        // PAID allotments still wait for Stripe (invoice.paid), so nobody can
        // subscribe-then-not-pay and keep the credits. config/billing.php →
        // grant_on_signup stands in for a wired-up Stripe in local dev; it
        // stays OFF in prod and in the test suite, where it would mint
        // credits nobody paid for.
        static::created(function (Team $team): void {
            $plan = $team->planObject();
            if ($plan->monthlyCredits() <= 0) {
                return;
            }

            $isFreeEntitlement = ! $plan->isPaid();
            if (! $isFreeEntitlement && ! config('billing.grant_on_signup')) {
                return;
            }

            app(CreditMeter::class)->grantMonthlyRenewal($team, [
                'source' => $isFreeEntitlement ? 'signup:free-tier' : 'signup:dev-auto-grant',
            ]);
        });
    }

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

    /** @return HasMany<Agent, $this> */
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
