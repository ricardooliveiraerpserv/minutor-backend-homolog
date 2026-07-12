<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            if (! Schema::hasColumn('messages', 'pinned_at')) {
                $table->timestamp('pinned_at')->nullable()->after('deleted_at');
            }
            if (! Schema::hasColumn('messages', 'pinned_by')) {
                $table->foreignId('pinned_by')->nullable()->after('pinned_at')->constrained('users')->nullOnDelete();
            }
            $table->index(['conversation_id', 'pinned_at']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            if (Schema::hasColumn('messages', 'pinned_by')) {
                $table->dropForeign(['pinned_by']);
                $table->dropColumn('pinned_by');
            }
            if (Schema::hasColumn('messages', 'pinned_at'))  $table->dropColumn('pinned_at');
        });
    }
};
