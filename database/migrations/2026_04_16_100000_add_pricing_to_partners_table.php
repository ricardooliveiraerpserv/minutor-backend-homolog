<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->string('pricing_type', 20)->default('fixed')->after('active'); // fixed | variable
            $table->decimal('hourly_rate', 10, 2)->nullable()->after('pricing_type');
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn(['pricing_type', 'hourly_rate']);
        });
    }
};
