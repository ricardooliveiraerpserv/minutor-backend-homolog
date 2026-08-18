<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('contract_hours_alerts')) {
            return;
        }

        Schema::create('contract_hours_alerts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('contract_id')->nullable();

            // Faixa atingida (70, 80, 90, 100, 110, 120, ...) e o "limite" no momento do
            // disparo (snapshot inteiro das horas contratadas/acumuladas). O par
            // (project_id, band, available_snapshot) garante 1 envio por faixa/período —
            // e RE-ARMA automaticamente quando um aporte/renovação muda o limite.
            $table->smallInteger('band');
            $table->integer('available_snapshot');

            $table->decimal('percentual', 6, 2)->default(0);
            $table->decimal('available', 10, 2)->default(0);   // limite de horas
            $table->decimal('consumed', 10, 2)->default(0);    // aprovadas + pendentes
            $table->decimal('approved', 10, 2)->nullable();    // só aprovadas (informativo)
            $table->decimal('balance', 10, 2)->default(0);     // saldo (negativo = excedente)
            $table->string('basis', 20)->nullable();           // fixed | monthly | closed
            $table->string('classification', 30)->nullable();  // Atenção | Limite atingido | Excedido

            $table->jsonb('recipients_to')->nullable();
            $table->jsonb('recipients_cc')->nullable();

            $table->string('status', 20)->default('pending');  // sent | failed | no_recipient
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            $table->unique(['project_id', 'band', 'available_snapshot'], 'contract_hours_alerts_dedup_uq');
            $table->index('project_id');
            $table->index('contract_id');

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_hours_alerts');
    }
};
