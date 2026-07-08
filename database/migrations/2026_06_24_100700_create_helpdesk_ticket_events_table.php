<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Help Desk — Timeline/histórico do chamado (padrão CrmOpportunityEvent). Imutável:
 * nunca update/delete. Alimenta a linha do tempo do ticket e os indicadores de SLA.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('helpdesk_ticket_events')) {
            return;
        }
        Schema::create('helpdesk_ticket_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('helpdesk_tickets')->cascadeOnDelete();
            $table->string('event_type', 40);   // created|status_changed|assigned|priority_changed|first_response|resolved|reopened|closed|sla_breached|note|comment
            $table->string('field', 40)->nullable();
            $table->string('from_value', 255)->nullable();
            $table->string('to_value', 255)->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('helpdesk_ticket_events');
    }
};
