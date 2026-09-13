<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outbound webhooks — the app's only integration surface (there is no
 * public API by design). A team registers a URL and the events it wants;
 * App\Services\WebhookDispatcher POSTs a signed JSON body per event and
 * records the last delivery outcome here so the settings page can show
 * whether the endpoint is alive.
 *
 * secret is the HMAC key shown ONCE at creation and stored encrypted;
 * events is a JSON list of event names (TeamWebhook::EVENTS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_webhooks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('url', 2000);
            $table->text('secret');
            $table->json('events');
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('last_status')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamp('last_delivered_at')->nullable();
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestamps();

            $table->index(['team_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_webhooks');
    }
};
