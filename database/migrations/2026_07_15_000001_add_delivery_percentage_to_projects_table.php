<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (!Schema::hasColumn('projects', 'delivery_percentage')) {
                $table->decimal('delivery_percentage', 5, 2)->nullable()->after('expected_end_date')
                    ->comment('Percentual de entrega informado manualmente (0-100)');
            }
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (Schema::hasColumn('projects', 'delivery_percentage')) {
                $table->dropColumn('delivery_percentage');
            }
        });
    }
};
