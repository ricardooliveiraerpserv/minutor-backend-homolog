<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Estrutura de conteúdo (JSON) das comunicações/modelos + renomeia tipo 'campanha' → 'marketing'. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communications', function (Blueprint $table) {
            $table->json('structure')->nullable()->after('message');
        });
        Schema::table('communication_templates', function (Blueprint $table) {
            $table->json('structure')->nullable()->after('message');
        });

        DB::table('communications')->where('tipo_comunicacao', 'campanha')->update(['tipo_comunicacao' => 'marketing']);
        DB::table('communication_templates')->where('tipo_comunicacao', 'campanha')->update(['tipo_comunicacao' => 'marketing']);
    }

    public function down(): void
    {
        DB::table('communications')->where('tipo_comunicacao', 'marketing')->update(['tipo_comunicacao' => 'campanha']);
        DB::table('communication_templates')->where('tipo_comunicacao', 'marketing')->update(['tipo_comunicacao' => 'campanha']);
        Schema::table('communications', fn (Blueprint $t) => $t->dropColumn('structure'));
        Schema::table('communication_templates', fn (Blueprint $t) => $t->dropColumn('structure'));
    }
};
