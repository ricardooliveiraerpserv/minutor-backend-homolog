<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro central de ENVIOS de e-mail dos workflows (uma linha por tentativa de envio).
 * Alimenta a tela "Envios por rotina". Gravado best-effort pelo WorkflowMailer e pelo
 * serviço de alerta de consumo — nunca derruba o envio em si.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('workflow_email_logs')) return;
        Schema::create('workflow_email_logs', function (Blueprint $table) {
            $table->id();
            $table->string('routine_key', 80)->index();     // key do config/workflows.php (ex.: contract.hours_alert)
            $table->string('domain', 40)->nullable();        // domínio (Contratos, Despesas, …) p/ agrupar
            $table->string('label', 160)->nullable();        // rótulo amigável da rotina
            $table->string('subject', 300)->nullable();
            $table->jsonb('recipients_to')->nullable();      // ['a@x.com', …]
            $table->jsonb('recipients_cc')->nullable();
            $table->string('status', 20)->default('sent');   // sent | failed | no_recipient
            $table->text('error')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->unsignedBigInteger('contract_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('company_id')->nullable()->index(); // multi-empresa
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_email_logs');
    }
};
