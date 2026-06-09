<?php

namespace App\Billing;

use App\Models\Team;

/**
 * Decides which credit-burn thresholds a team has newly crossed since its
 * last alert state. Pure function — no IO, no notifications. Caller is
 * responsible for queueing notifications and persisting the new state.
 *
 * The two return arrays are complementary:
 *  - newlyCrossed: thresholds the team JUST crossed (consume side) — these need notifications
 *  - dropped:      thresholds the team is no longer past (top-up side) — these clear from state
 *
 * Thresholds are expressed as "percent USED" (1 - balance/grant):
 *   50% used = balance dropped below 50% of grant
 *   80% used = below 20% of grant
 *   95% used = below  5% of grant
 *
 * Plans whose monthlyCredits === 0 (e.g. Business / negotiated) are
 * exempt — no percentage is meaningful without a denominator.
 */
class EvaluateCreditAlerts
{
    /** @var array<int, int> ordered ascending */
    public const THRESHOLDS = [50, 80, 95];

    /**
     * @return array{newlyCrossed: array<int, int>, fired: array<int, string>}
     */
    public function evaluate(Team $team): array
    {
        $grant = $team->planObject()->monthlyCredits();
        if ($grant <= 0) {
            return ['newlyCrossed' => [], 'fired' => $this->normalizedFired($team)];
        }

        $balance = max(0, (int) $team->credit_balance);
        $usedPercent = (int) floor((1.0 - ($balance / $grant)) * 100);

        $alreadyFired = $this->normalizedFired($team);
        $currentlyCrossed = array_values(array_filter(
            self::THRESHOLDS,
            fn (int $t) => $usedPercent >= $t,
        ));

        $newlyCrossed = array_values(array_diff(
            $currentlyCrossed,
            array_map('intval', $alreadyFired),
        ));

        return [
            'newlyCrossed' => $newlyCrossed,
            // The post-evaluation `fired` state is exactly the set
            // currently crossed — supports both directions: crossing
            // downward adds, top-ups that lift balance back above
            // a threshold remove.
            'fired' => array_map(fn (int $t) => (string) $t, $currentlyCrossed),
        ];
    }

    /**
     * Reset to empty — used by monthly renewals (balance fully restored).
     *
     * @return array<int, string>
     */
    public function reset(): array
    {
        return [];
    }

    /**
     * @return array<int, string>
     */
    protected function normalizedFired(Team $team): array
    {
        /** @var mixed $raw */
        $raw = $team->alert_thresholds_fired;
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $v) {
            $out[] = (string) $v;
        }

        return $out;
    }
}
