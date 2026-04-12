<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'partner_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('partner_id')
                  ->nullable()
                  ->constrained('partners')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('users', 'partner_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\Partner::class);
            $table->dropColumn('partner_id');
        });
    }
};
