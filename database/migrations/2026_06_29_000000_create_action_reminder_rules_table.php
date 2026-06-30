<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Regras de RECORRÊNCIA dos lembretes de ações não resolvidas (apontamentos rejeitados/ajuste,
 * aprovações, despesas...). O admin define, por tipo de ação, se re-lembra e a cada quantas
 * horas/dias. O comando actions:remind-pending reabre o pop-up + reenvia o e-mail na cadência.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_reminder_rules', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();                 // approve_ts, fix_ts_rejected, ...
            $table->boolean('enabled')->default(false);
            $table->string('unit')->default('days');         // 'hours' | 'days'
            $table->unsignedInteger('interval')->default(1); // a cada X horas/dias
            $table->timestamp('last_fired_at')->nullable();  // último disparo (controla a cadência)
            $table->unsignedBigInteger('notification_id')->nullable(); // aviso persistente reusado a cada disparo
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_reminder_rules');
    }
};
