<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1.1 — permite auditar 'numero_reservado' em document_events ANTES de existir um Document
 * (a reserva ocorre na criação da proposta). document_id vira nullable + contexto da entidade.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('document_events', function (Blueprint $table) {
            if (!Schema::hasColumn('document_events', 'codigo')) {
                $table->string('codigo', 40)->nullable()->after('event_type');
            }
            if (!Schema::hasColumn('document_events', 'entity_type')) {
                $table->string('entity_type', 40)->nullable()->after('codigo');
            }
            if (!Schema::hasColumn('document_events', 'entity_id')) {
                $table->unsignedBigInteger('entity_id')->nullable()->after('entity_type');
            }
        });
        DB::statement('ALTER TABLE document_events ALTER COLUMN document_id DROP NOT NULL');
    }

    public function down(): void
    {
        Schema::table('document_events', function (Blueprint $table) {
            foreach (['codigo', 'entity_type', 'entity_id'] as $c) {
                if (Schema::hasColumn('document_events', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
        // document_id volta a NOT NULL só se não houver linhas nulas
        if (DB::table('document_events')->whereNull('document_id')->doesntExist()) {
            DB::statement('ALTER TABLE document_events ALTER COLUMN document_id SET NOT NULL');
        }
    }
};
