<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedSmallInteger('proj_sequence')->nullable()->after('code')
                ->comment('Sequência numérica do projeto pai por cliente');
            $table->string('proj_year', 2)->nullable()->after('proj_sequence')
                ->comment('Ano de criação (2 dígitos)');
            $table->unsignedSmallInteger('child_sequence')->nullable()->after('proj_year')
                ->comment('Sequência do projeto filho dentro do pai');
            $table->boolean('is_manual_code')->default(false)->after('child_sequence')
                ->comment('Código foi definido manualmente — não sobrescrever');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['proj_sequence', 'proj_year', 'child_sequence', 'is_manual_code']);
        });
    }
};
