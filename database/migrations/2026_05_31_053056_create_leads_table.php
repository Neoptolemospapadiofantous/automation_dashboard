<?php

use App\Enums\LeadStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();

            // Ownership: leads belong to a team (sales pod) and may be
            // delegated to a specific rep.
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            // Core contact details.
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('company')->nullable();
            $table->string('source')->default('manual');

            // Pipeline state.
            $table->string('status')->default(LeadStatus::New->value)->index();
            $table->unsignedTinyInteger('score')->default(0);

            // Voiceflow linkage + captured conversation variables.
            $table->string('voiceflow_user_id')->nullable()->index();
            $table->json('captured')->nullable();
            $table->text('notes')->nullable();

            $table->timestamp('last_contacted_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
