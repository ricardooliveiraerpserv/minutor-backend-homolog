<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fechamento_clientes', function (Blueprint $table) {
            if (!Schema::hasColumn('fechamento_clientes', 'desconto')) {
                $table->decimal('desconto', 14, 2)->default(0)->after('total_geral');
            }
            if (!Schema::hasColumn('fechamento_clientes', 'desconto_descricao')) {
                $table->text('desconto_descricao')->nullable()->after('desconto');
            }
        });
    }

    public function down(): void
    {
        Schema::table('fechamento_clientes', function (Blueprint $table) {
            if (Schema::hasColumn('fechamento_clientes', 'desconto_descricao')) {
                $table->dropColumn('desconto_descricao');
            }
            if (Schema::hasColumn('fechamento_clientes', 'desconto')) {
                $table->dropColumn('desconto');
            }
        });
    }
};
