<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Convert agents.webhook_secret from cleartext to Laravel-encrypted at rest.
 *
 * Strategy: read each existing row's cleartext value, encrypt it once via
 * encrypt(), write back via raw DB::table (bypassing the Eloquent cast, which
 * would re-encrypt the already-cleartext input). After this migration runs,
 * the Agent model's `webhook_secret => 'encrypted'` cast handles all
 * subsequent reads + writes transparently.
 *
 * Idempotency: existing values that already look encrypted (start with the
 * Laravel JSON envelope `{"iv":`) are left alone, so re-running this migration
 * is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Column already exists as a non-null string; no schema change needed
        // here — just a one-time value rewrite.
        if (! Schema::hasTable('agents')) {
            return;
        }

        $rows = DB::table('agents')->select(['id', 'webhook_secret'])->get();

        foreach ($rows as $row) {
            $current = (string) ($row->webhook_secret ?? '');
            if ($current === '') {
                continue;
            }
            // Heuristic skip: Laravel's encrypter outputs a base64-encoded JSON
            // envelope. If the column already decodes to one, do not double-encrypt.
            if ($this->looksEncrypted($current)) {
                continue;
            }

            DB::table('agents')->where('id', $row->id)->update([
                'webhook_secret' => encrypt($current),
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('agents')) {
            return;
        }

        $rows = DB::table('agents')->select(['id', 'webhook_secret'])->get();

        foreach ($rows as $row) {
            $current = (string) ($row->webhook_secret ?? '');
            if ($current === '' || ! $this->looksEncrypted($current)) {
                continue;
            }

            try {
                DB::table('agents')->where('id', $row->id)->update([
                    'webhook_secret' => decrypt($current),
                ]);
            } catch (Throwable) {
                // Already-cleartext or undecryptable — leave as-is.
            }
        }
    }

    private function looksEncrypted(string $value): bool
    {
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            return false;
        }
        $parsed = json_decode($decoded, true);

        return is_array($parsed) && isset($parsed['iv'], $parsed['value'], $parsed['mac']);
    }
};
