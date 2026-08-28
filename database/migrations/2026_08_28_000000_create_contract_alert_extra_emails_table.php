<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-mails avulsos (destinatários adicionais) do alerta de consumo de horas, por contrato.
 *
 * NÃO são contatos: existem exclusivamente como destinatário extra do alerta daquele
 * contrato. Não criam customer_contact nem contract_contact, não aparecem em outras
 * comunicações e não se propagam para outro contrato/projeto.
 *
 * Unicidade por (contract_id, normalized_email) — normalized = lower(trim(email)) —
 * impede o mesmo e-mail duas vezes no mesmo contrato (dedup case-insensitive).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_alert_extra_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->string('email');
            $table->string('normalized_email');
            $table->timestamps();

            $table->unique(['contract_id', 'normalized_email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_alert_extra_emails');
    }
};
