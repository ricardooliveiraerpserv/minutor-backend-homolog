<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Escopo de visibilidade (de quem o user pode ver dados): self|team|all
            $table->string('bot_visibility', 16)->default('self')->after('bot_allowed_scopes');
            // Override por scope: { payroll: 'self', billing: 'denied', ... }
            $table->jsonb('bot_scope_overrides')->nullable()->after('bot_visibility');
        });

        // Backfill por perfil:
        // admin / administrativo  → all
        // coordenador             → team
        // demais (consultor, cliente, parceiro_admin)  → self
        DB::table('users')->whereIn('type', ['admin', 'administrativo'])->update(['bot_visibility' => 'all']);
        DB::table('users')->where('type', 'coordenador')->update(['bot_visibility' => 'team']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['bot_visibility', 'bot_scope_overrides']);
        });
    }
};
