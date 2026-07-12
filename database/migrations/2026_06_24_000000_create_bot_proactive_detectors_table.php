<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_proactive_detectors', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            // Tipos suportados: bank_hours_threshold, expense_payment_age,
            // timesheet_pending_age, ticket_stale_age, late_timesheets, sql, custom
            $table->string('detector_type', 60);
            $table->jsonb('config')->default('{}');
            $table->string('severity', 20)->default('medium');
            $table->string('source', 30)->default('ai');
            $table->string('event_type', 40)->default('financial_alert');
            $table->unsignedInteger('dedupe_window_hours')->default(24);
            $table->boolean('is_system')->default(false);
            $table->timestamp('last_run_at')->nullable();
            $table->unsignedInteger('last_run_alerts')->default(0);
            $table->text('last_run_error')->nullable();
            $table->timestamps();

            $table->index(['active', 'detector_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_proactive_detectors');
    }
};
