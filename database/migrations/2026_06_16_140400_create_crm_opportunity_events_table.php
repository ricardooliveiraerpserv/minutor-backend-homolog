<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM — eventos/histórico da oportunidade (padrão ContractEvent). Alimenta o histórico da
 * oportunidade e a timeline única da empresa. Imutável (nunca update/delete).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('crm_opportunity_events')) {
            return;
        }
        Schema::create('crm_opportunity_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opportunity_id')->constrained('crm_opportunities')->cascadeOnDelete();
            $table->string('event_type', 40);            // created|stage_changed|won|lost|task_done|note|product_changed|converted
            $table->string('field', 40)->nullable();
            $table->string('from_value', 255)->nullable();
            $table->string('to_value', 255)->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['opportunity_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_opportunity_events');
    }
};
