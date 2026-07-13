<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'is_coordinator')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_coordinator')->default(false)->after('is_executive')
                ->comment('Marca usuário NÃO-coordenador-nativo (ex.: admin) como coordenador — aparece nas colunas do Kanban e no filtro de coordenadores.');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'is_coordinator')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_coordinator');
        });
    }
};
