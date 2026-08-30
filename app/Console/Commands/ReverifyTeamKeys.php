<?php

namespace App\Console\Commands;

use App\Billing\OwnKey;
use App\Models\TeamProviderKey;
use Illuminate\Console\Command;

/**
 * Nightly re-probe of customer-supplied provider keys (BYOK).
 *
 * A key is verified when it is stored and whenever the operator clicks
 * Re-verify — but a key revoked on the provider's side after that only
 * surfaces when a visitor's turn fails. This closes the gap: every stored
 * key gets the same 1-token probe OwnKey::verify uses, and the result is
 * written back exactly the way the Re-verify button writes it — last_error
 * set or cleared, last_verified_at advanced only on success.
 *
 * It touches ONLY team_provider_keys. The platform keys and the
 * providers:health-check tripwire are a different ledger and stay untouched.
 */
class ReverifyTeamKeys extends Command
{
    protected $signature = 'providers:reverify-team-keys';

    protected $description = 'Re-probe every customer-supplied provider key and record last_error / last_verified_at.';

    public function handle(OwnKey $ownKey): int
    {
        $ok = $failed = 0;

        TeamProviderKey::query()->orderBy('id')->chunkById(50, function ($keys) use ($ownKey, &$ok, &$failed) {
            foreach ($keys as $key) {
                $error = $ownKey->verify($key->provider, $key->api_key);

                $key->forceFill([
                    'last_error' => $error,
                    'last_verified_at' => $error === null ? now() : $key->last_verified_at,
                ])->save();

                if ($error === null) {
                    $ok++;
                } else {
                    $failed++;
                    $this->line("team {$key->team_id} {$key->provider} {$key->key_hint}: {$error}");
                }
            }
        });

        $this->info("Re-verified {$ok} key(s) ok, {$failed} failing.");

        return self::SUCCESS;
    }
}
