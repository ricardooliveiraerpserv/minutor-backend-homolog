<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table) {
            if (! Schema::hasColumn('conversation_participants', 'muted_until')) {
                $table->timestamp('muted_until')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table) {
            if (Schema::hasColumn('conversation_participants', 'muted_until')) {
                $table->dropColumn('muted_until');
            }
        });
    }
};
