<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            if (!Schema::hasColumn('timesheets', 'consultant_release_denied')) {
                $table->boolean('consultant_release_denied')->default(false);
            }
            if (!Schema::hasColumn('timesheets', 'consultant_release_denied_at')) {
                $table->timestamp('consultant_release_denied_at')->nullable();
            }
            if (!Schema::hasColumn('timesheets', 'consultant_release_denied_by')) {
                $table->unsignedBigInteger('consultant_release_denied_by')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            foreach (['consultant_release_denied', 'consultant_release_denied_at', 'consultant_release_denied_by'] as $c) {
                if (Schema::hasColumn('timesheets', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
