<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM — log de eventos NO NÍVEL DA EMPRESA (customers). Permite que a timeline única
 * comece já na fase Lead (lead_created, interaction, qualified, prospect, lost),
 * complementando crm_opportunity_events + contract_events. NÃO duplica empresa.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('crm_customer_events')) {
            return;
        }
        Schema::create('crm_customer_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 40);          // lead_created | interaction | qualified | prospect | lost | stage_changed
            $table->string('label', 200)->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_customer_events');
    }
};
