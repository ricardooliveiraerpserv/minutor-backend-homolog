<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('project_messages', function (Blueprint $table) {
            if (!Schema::hasColumn('project_messages', 'edited_at')) {
                $table->timestamp('edited_at')->nullable()->after('visibility');
            }
        });
    }

    public function down(): void
    {
        Schema::table('project_messages', function (Blueprint $table) {
            if (Schema::hasColumn('project_messages', 'edited_at')) {
                $table->dropColumn('edited_at');
            }
        });
    }
};
