<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_hourly_rate_logs', function (Blueprint $table) {
            $table->enum('old_consultant_type', ['horista', 'banco_de_horas', 'fixo'])->nullable()->after('reason');
            $table->enum('new_consultant_type', ['horista', 'banco_de_horas', 'fixo'])->nullable()->after('old_consultant_type');
        });
    }

    public function down(): void
    {
        Schema::table('user_hourly_rate_logs', function (Blueprint $table) {
            $table->dropColumn(['old_consultant_type', 'new_consultant_type']);
        });
    }
};
