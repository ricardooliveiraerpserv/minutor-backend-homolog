<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_agents', function (Blueprint $table) {
            if (! Schema::hasColumn('bot_agents', 'allowed_scopes')) {
                $table->jsonb('allowed_scopes')->nullable()
                    ->comment('Lista de scopes que o agent pode invocar via tools. Null = todos.');
            }
        });

        // Default: agents existentes começam com TODOS os scopes (compatibilidade total).
        DB::table('bot_agents')->whereNull('allowed_scopes')->update([
            'allowed_scopes' => json_encode([
                'customer', 'project', 'contract', 'financial',
                'billing', 'payroll', 'bankhours', 'approvals', 'overview',
            ]),
        ]);
    }

    public function down(): void
    {
        Schema::table('bot_agents', function (Blueprint $table) {
            if (Schema::hasColumn('bot_agents', 'allowed_scopes')) {
                $table->dropColumn('allowed_scopes');
            }
        });
    }
};
