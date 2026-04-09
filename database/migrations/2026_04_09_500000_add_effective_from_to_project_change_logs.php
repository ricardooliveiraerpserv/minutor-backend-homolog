<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('project_change_logs', 'effective_from')) {
            Schema::table('project_change_logs', function (Blueprint $table) {
                $table->date('effective_from')->nullable()->after('reason');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('project_change_logs', 'effective_from')) {
            Schema::table('project_change_logs', function (Blueprint $table) {
                $table->dropColumn('effective_from');
            });
        }
    }
};
