<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De-para de STATUS entre o Help Desk (Minutor) e o Movidesk (por empresa).
 *
 * Entrada (Movidesk → HD): dado (base_status, status_text) do Movidesk, acha o status HD.
 *   - casa 1º por (base_status + status_text) exato; senão por base_status; senão default.
 * Saída  (HD → Movidesk): a linha marcada `is_outbound_default` guarda o status Movidesk
 *   para onde ESTE status HD empurraria — usado APENAS na Fase 2 (hoje NÃO escrevemos no Movidesk).
 *
 * Vários status Movidesk podem apontar para o mesmo status HD (ex.: "Pausado", "Pendente
 * aprovação" → "Aguardando cliente"), e status HD sem par no Movidesk apontam para o base
 * mais próximo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('helpdesk_movidesk_status_map')) return;

        Schema::create('helpdesk_movidesk_status_map', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('helpdesk_status_id');
            $t->string('movidesk_base_status', 40)->nullable();   // New|InAttendance|Stopped|Resolved|Closed|Canceled
            $t->string('movidesk_status_text', 120)->nullable();  // sub-status ("Pendente cliente", "Agendado", ...)
            $t->boolean('is_outbound_default')->default(false);   // linha-alvo ao empurrar este status HD (Fase 2)
            $t->timestamps();

            $t->index(['company_id', 'helpdesk_status_id'], 'idx_hdmd_map_company_status');
            $t->index(['company_id', 'movidesk_base_status', 'movidesk_status_text'], 'idx_hdmd_map_inbound');
            $t->foreign('helpdesk_status_id')->references('id')->on('helpdesk_statuses')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('helpdesk_movidesk_status_map');
    }
};
