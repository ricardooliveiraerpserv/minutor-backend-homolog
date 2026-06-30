<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Notificações podem mirar MÚLTIPLOS clientes (antes era 1 só via target_customer_id). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications_center', function (Blueprint $table) {
            $table->json('target_customer_ids')->nullable()->after('target_customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('notifications_center', fn (Blueprint $table) => $table->dropColumn('target_customer_ids'));
    }
};
