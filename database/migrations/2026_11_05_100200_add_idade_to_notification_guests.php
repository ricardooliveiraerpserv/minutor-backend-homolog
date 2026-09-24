<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Idade do familiar (relevante quando parentesco = Filho(a)).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_guests', function (Blueprint $table) {
            if (!Schema::hasColumn('notification_guests', 'idade')) {
                $table->unsignedSmallInteger('idade')->nullable()->after('parentesco');
            }
        });
    }

    public function down(): void
    {
        Schema::table('notification_guests', function (Blueprint $table) {
            if (Schema::hasColumn('notification_guests', 'idade')) {
                $table->dropColumn('idade');
            }
        });
    }
};
