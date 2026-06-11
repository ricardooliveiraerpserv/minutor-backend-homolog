<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Auditoria de alteração de aporte (On Demand e demais).
     * Espelha project_change_logs, mas por hour_contribution.
     */
    public function up(): void
    {
        Schema::create('hour_contribution_change_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hour_contribution_id')->constrained('hour_contributions')->onDelete('cascade');
            $table->foreignId('project_id')->constrained('projects')->onDelete('cascade');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('field_name');              // hourly_rate | contributed_hours | contributed_at
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->text('reason')->nullable();        // motivo da alteração (opcional)
            $table->date('effective_from')->nullable(); // competência/vigência do aporte no momento da mudança
            $table->timestamps();

            $table->index('hour_contribution_id');
            $table->index('project_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hour_contribution_change_logs');
    }
};
