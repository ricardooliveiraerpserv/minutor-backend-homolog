<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            if (!Schema::hasColumn('partners', 'document')) {
                $table->string('document', 20)->nullable()->after('name');
            }
            if (!Schema::hasColumn('partners', 'email')) {
                $table->string('email', 255)->nullable()->after('document');
            }
            if (!Schema::hasColumn('partners', 'phone')) {
                $table->string('phone', 20)->nullable()->after('email');
            }
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn(array_filter(
                ['document', 'email', 'phone'],
                fn($col) => Schema::hasColumn('partners', $col)
            ));
        });
    }
};
