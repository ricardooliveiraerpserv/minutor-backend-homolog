<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'bot_allowed_scopes')) {
                $table->jsonb('bot_allowed_scopes')->nullable()
                    ->comment('Restringe quais áreas o user pode consultar via @bot. Null = sem restrição (libera todas as áreas que os agents permitirem).');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'bot_allowed_scopes')) {
                $table->dropColumn('bot_allowed_scopes');
            }
        });
    }
};
