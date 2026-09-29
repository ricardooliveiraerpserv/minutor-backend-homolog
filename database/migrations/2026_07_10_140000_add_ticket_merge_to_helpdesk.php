<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mesclagem de chamados (Help Desk):
 *  - helpdesk_tickets.merged_into_id  → quando um chamado é mesclado, aponta pro chamado de DESTINO
 *    (o chamado-origem some das listagens enquanto estiver mesclado).
 *  - helpdesk_ticket_comments.origin_ticket_id → chamado onde a interação foi ORIGINALMENTE criada
 *    (essencial p/ desmesclar: no unmerge, só as interações com origin = origem voltam pra ela).
 *  - helpdesk_ticket_merges → log auditável de cada operação de mescla/desmescla.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('helpdesk_tickets', 'merged_into_id')) {
            Schema::table('helpdesk_tickets', function (Blueprint $table) {
                $table->foreignId('merged_into_id')->nullable()->after('previous_ticket_id')
                    ->constrained('helpdesk_tickets')->nullOnDelete();
                $table->index('merged_into_id');
            });
        }

        if (!Schema::hasColumn('helpdesk_ticket_comments', 'origin_ticket_id')) {
            Schema::table('helpdesk_ticket_comments', function (Blueprint $table) {
                // Sem FK constraint p/ não travar exclusão do chamado de origem (fluxo normal).
                $table->unsignedBigInteger('origin_ticket_id')->nullable()->after('ticket_id');
                $table->index('origin_ticket_id');
            });
        }

        if (!Schema::hasTable('helpdesk_ticket_merges')) {
            Schema::create('helpdesk_ticket_merges', function (Blueprint $table) {
                $table->id();
                $table->string('action', 12);                 // 'merge' | 'unmerge'
                $table->unsignedBigInteger('target_ticket_id'); // destino
                $table->unsignedBigInteger('source_ticket_id'); // origem
                $table->string('target_number', 40)->nullable(); // nº p/ auditoria mesmo após exclusão
                $table->string('source_number', 40)->nullable();
                $table->unsignedBigInteger('performed_by')->nullable();
                $table->json('options')->nullable();           // {keep_requesters, keep_cc, keep_tags}
                $table->json('meta')->nullable();              // ex.: status da origem no momento (p/ restaurar)
                $table->timestamps();
                $table->index(['target_ticket_id', 'action']);
                $table->index('source_ticket_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('helpdesk_ticket_merges');
        if (Schema::hasColumn('helpdesk_ticket_comments', 'origin_ticket_id')) {
            Schema::table('helpdesk_ticket_comments', fn (Blueprint $t) => $t->dropColumn('origin_ticket_id'));
        }
        if (Schema::hasColumn('helpdesk_tickets', 'merged_into_id')) {
            Schema::table('helpdesk_tickets', fn (Blueprint $t) => $t->dropConstrainedForeignId('merged_into_id'));
        }
    }
};
