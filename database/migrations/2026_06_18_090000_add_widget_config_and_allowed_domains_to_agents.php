<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Embeddable widget v2.
 *
 * - agents.widget_config: per-agent appearance/behavior for the floating
 *   chat widget (accent color, position, launcher text/icon, panel title,
 *   avatar, proactive greeting, branding toggle). Null → sensible brand
 *   defaults (see Agent::widgetConfig()).
 * - agents.allowed_domains: optional allowlist of host domains permitted to
 *   embed this agent's widget. Empty/null → no restriction (permissive,
 *   backward-compatible). When set, the loader refuses to mount and the
 *   launch/interact endpoints reject off-list origins. See
 *   app/Support/Embed/DomainAllowlist.php for the matching rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            // No ->after(): keeps this migration independent of other
            // in-flight branches that also add agents columns.
            $table->json('widget_config')->nullable();
            $table->json('allowed_domains')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn(['widget_config', 'allowed_domains']);
        });
    }
};
