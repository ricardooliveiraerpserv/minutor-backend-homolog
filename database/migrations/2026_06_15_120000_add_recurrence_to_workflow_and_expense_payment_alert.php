<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recorrência do aviso de "despesa pendente de pagamento":
 * - workflow_templates.recurrence_days: a cada quantos dias reenviar o aviso
 *   enquanto a despesa aprovada não for paga (configurado na Central; 0/null = off).
 * - expenses.payment_alert_last_sent_at: último envio do aviso recorrente, para o
 *   comando agendado saber quando reenviar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_templates', function (Blueprint $table) {
            if (!Schema::hasColumn('workflow_templates', 'recurrence_days')) {
                $table->unsignedSmallInteger('recurrence_days')->nullable()->after('body');
            }
        });

        Schema::table('expenses', function (Blueprint $table) {
            if (!Schema::hasColumn('expenses', 'payment_alert_last_sent_at')) {
                $table->timestamp('payment_alert_last_sent_at')->nullable()->after('reviewed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('workflow_templates', function (Blueprint $table) {
            if (Schema::hasColumn('workflow_templates', 'recurrence_days')) {
                $table->dropColumn('recurrence_days');
            }
        });

        Schema::table('expenses', function (Blueprint $table) {
            if (Schema::hasColumn('expenses', 'payment_alert_last_sent_at')) {
                $table->dropColumn('payment_alert_last_sent_at');
            }
        });
    }
};
