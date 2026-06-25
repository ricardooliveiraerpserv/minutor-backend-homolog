<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca quando o e-mail de "Saving" (finalização antes do prazo) já foi enviado
 * automaticamente — evita reenvio no auto-disparo (o botão manual reenvia à parte).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (!Schema::hasColumn('projects', 'saving_notified_at')) {
                $table->timestamp('saving_notified_at')->nullable()->after('encerramento_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (Schema::hasColumn('projects', 'saving_notified_at')) $table->dropColumn('saving_notified_at');
        });
    }
};
