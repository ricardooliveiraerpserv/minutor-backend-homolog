<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central de Comunicação (EXTERNA, com clientes) — separada da Central de Atividades (interna).
 *  - distribution_lists: seleções salvas e reutilizáveis (clientes + usuários + e-mails externos)
 *  - communications: histórico dos envios (tipo, alvos, nº destinatários, data, autor)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distribution_lists', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 200);
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->json('customer_ids')->nullable();
            $table->json('user_ids')->nullable();
            $table->json('external_emails')->nullable();
            $table->timestamps();
        });

        Schema::create('communications', function (Blueprint $table) {
            $table->id();
            $table->string('tipo_comunicacao', 20)->default('aviso');  // aviso | formal | campanha
            $table->string('title', 250);
            $table->text('message');
            $table->json('customer_ids')->nullable();
            $table->json('user_ids')->nullable();
            $table->json('external_emails')->nullable();
            $table->boolean('all_customers')->default(false);
            $table->foreignId('lista_distribuicao_id')->nullable()->constrained('distribution_lists')->nullOnDelete();
            $table->unsignedInteger('recipients_count')->default(0);
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communications');
        Schema::dropIfExists('distribution_lists');
    }
};
