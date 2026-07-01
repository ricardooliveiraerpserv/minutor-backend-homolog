<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Metas comerciais (quota) por competência. user_id NULL = meta da EMPRESA (global);
 * user_id preenchido = meta do vendedor. A meta dá contexto ao forecast (gap, % atingido).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('crm_sales_targets')) return;
        Schema::create('crm_sales_targets', function (Blueprint $table) {
            $table->id();
            $table->string('periodo', 7);                         // YYYY-MM
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('valor_meta', 14, 2)->default(0);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['periodo', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_sales_targets');
    }
};
