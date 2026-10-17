<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('conecta_ticket_migration_log')) {
            return;
        }
        Schema::create('conecta_ticket_migration_log', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('customer_id')->nullable()->index();
            // Ticket de origem na ERPSERV (null p/ "novos" que nunca viram ticket aqui).
            $t->unsignedBigInteger('erpserv_ticket_id')->nullable()->index();
            $t->string('movidesk_id', 100)->nullable()->index();
            // Chave de upsert espelhando a Conecta: (source_system, external_ref).
            $t->string('source_system', 40)->nullable();
            $t->string('external_ref', 100)->nullable();
            $t->string('subject', 255)->nullable();
            $t->string('status_text', 120)->nullable();
            // 'legado' = cópia de ticket já existente | 'novo' = log de entrada de ticket novo.
            $t->string('kind', 20)->default('legado');
            $t->unsignedBigInteger('conecta_ticket_id')->nullable();
            // 'pending' | 'ok' | 'error'
            $t->string('result', 20)->default('pending')->index();
            $t->text('error_msg')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->integer('comments_sent')->nullable();
            $t->integer('attachments_sent')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
            // 1 linha por ticket de origem (idempotência do push/backfill).
            $t->unique(['source_system', 'external_ref'], 'conecta_tml_source_ref_unq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conecta_ticket_migration_log');
    }
};
