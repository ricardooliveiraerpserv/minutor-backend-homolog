<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inclusão manual de reajuste ganha:
 *  - customer_id (opcional): vincula um cliente cadastrado p/ puxar os e-mails
 *    administrativos do cadastro dele como destinatários padrão.
 *  - notify_emails (json): destinatários salvos p/ o próximo reajuste.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manual_reajustes', function (Blueprint $table) {
            if (!Schema::hasColumn('manual_reajustes', 'customer_id')) {
                $table->foreignId('customer_id')->nullable()->after('cliente_nome')
                    ->constrained('customers')->nullOnDelete();
            }
            if (!Schema::hasColumn('manual_reajustes', 'notify_emails')) {
                $table->json('notify_emails')->nullable()->after('pct_reajuste');
            }
        });
    }

    public function down(): void
    {
        Schema::table('manual_reajustes', function (Blueprint $table) {
            if (Schema::hasColumn('manual_reajustes', 'customer_id')) {
                $table->dropConstrainedForeignId('customer_id');
            }
            if (Schema::hasColumn('manual_reajustes', 'notify_emails')) {
                $table->dropColumn('notify_emails');
            }
        });
    }
};
