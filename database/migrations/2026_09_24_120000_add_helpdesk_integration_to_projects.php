<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('projects', 'helpdesk_integration_enabled')) {
            Schema::table('projects', function (Blueprint $table) {
                // Vincular chamados do Help Desk a ESTE projeto: quando ligado, interações com
                // tempo viram apontamento neste projeto (mesmo sem contrato). Complementa
                // contracts.helpdesk_integration_enabled (usado por sustentação).
                $table->boolean('helpdesk_integration_enabled')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('projects', 'helpdesk_integration_enabled')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->dropColumn('helpdesk_integration_enabled');
            });
        }
    }
};
