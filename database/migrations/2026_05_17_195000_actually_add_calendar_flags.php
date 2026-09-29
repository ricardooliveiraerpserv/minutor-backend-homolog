<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * No-op idempotente: as colunas de calendar flags já entram pela migration
 * 2026_05_17_194634. Mantida por paridade de histórico com o homolog; só age
 * se por algum motivo as colunas ainda não existirem.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (!Schema::hasColumn('projects', 'allow_weekend_work')) {
                $table->boolean('allow_weekend_work')->default(false);
            }
            if (!Schema::hasColumn('projects', 'allow_holiday_work')) {
                $table->boolean('allow_holiday_work')->default(false);
            }
        });
    }

    public function down(): void
    {
        // no-op: o drop é responsabilidade da 194634.
    }
};
