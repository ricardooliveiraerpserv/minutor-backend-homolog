<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permissão por EMPRESA de usar o módulo de código-fonte (solicitar fontes / GMUD de projeto).
 * Default false — empresas que não trabalham com fonte (ex.: BIZIFY) não veem o CTA nem são
 * obrigadas a enviar fonte. Backfill: ERPSERV = true (é quem mantém o Protheus).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('companies', 'allow_source_requests')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->boolean('allow_source_requests')->default(false)->after('status');
            });
        }

        // Backfill: a empresa que mantém o código-fonte (ERPSERV) nasce habilitada.
        DB::table('companies')->where('slug', 'erpserv')->update(['allow_source_requests' => true]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'allow_source_requests')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('allow_source_requests');
            });
        }
    }
};
