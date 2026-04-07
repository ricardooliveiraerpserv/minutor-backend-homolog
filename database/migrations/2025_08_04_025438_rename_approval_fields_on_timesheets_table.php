<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            $table->renameColumn('approved_by', 'reviewed_by');
            $table->renameColumn('approved_at', 'reviewed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            $table->renameColumn('reviewed_by', 'approved_by');
            $table->renameColumn('reviewed_at', 'approved_at');
        });
    }
};
