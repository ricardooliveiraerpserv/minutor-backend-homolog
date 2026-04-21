<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fechamento_clientes', function (Blueprint $table) {
            $table->json('snapshot_pagamento')->nullable()->after('snapshot_despesas');
        });
    }

    public function down(): void
    {
        Schema::table('fechamento_clientes', function (Blueprint $table) {
            $table->dropColumn('snapshot_pagamento');
        });
    }
};
