<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->boolean('is_paid')->default(false)->after('charge_client');
            $table->unsignedBigInteger('paid_by')->nullable()->after('is_paid');
            $table->timestamp('paid_at')->nullable()->after('paid_by');

            $table->foreign('paid_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign(['paid_by']);
            $table->dropColumn(['is_paid', 'paid_by', 'paid_at']);
        });
    }
};
