<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user escalation SMS number (E.164). When set — and Twilio is
 * configured — a visitor's request for a human texts the team owner's
 * phone in addition to the bell + email, because speed-to-human is the
 * whole point of the takeover feature.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('notification_phone', 20)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('notification_phone');
        });
    }
};
