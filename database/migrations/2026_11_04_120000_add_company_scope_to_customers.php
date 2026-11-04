<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Empresa do CLIENTE (multi-empresa): erpserv | bizify | ambos.
 * Classificação inicial pelos PROJETOS do cliente (carimbo projects.company_id):
 *   - tem projeto BIZIFY (2) e ERPSERV (1) => 'ambos'
 *   - tem só projeto BIZIFY (2)            => 'bizify'
 *   - demais                               => 'erpserv'
 * is_bizify_customer é mantido em sincronia (bizify|ambos => true).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('customers', 'company_scope')) {
            Schema::table('customers', function (Blueprint $t) {
                $t->string('company_scope', 12)->default('erpserv')->after('is_bizify_customer');
            });
        }

        // Clientes por empresa dos projetos (ignora projetos soft-deletados).
        $biz = DB::table('projects')->whereNull('deleted_at')->where('company_id', 2)
            ->whereNotNull('customer_id')->distinct()->pluck('customer_id')->all();
        $erp = DB::table('projects')->whereNull('deleted_at')->where('company_id', 1)
            ->whereNotNull('customer_id')->distinct()->pluck('customer_id')->all();
        $erpSet = array_flip(array_map('intval', $erp));

        $bizIds = [];
        foreach (array_map('intval', $biz) as $cid) {
            $scope = isset($erpSet[$cid]) ? 'ambos' : 'bizify';
            DB::table('customers')->where('id', $cid)->update(['company_scope' => $scope, 'is_bizify_customer' => true]);
            $bizIds[] = $cid;
        }

        // Demais = erpserv (coluna já default) + is_bizify_customer false (regra: "os demais erpserv").
        DB::table('customers')
            ->when(!empty($bizIds), fn ($q) => $q->whereNotIn('id', $bizIds))
            ->update(['company_scope' => 'erpserv', 'is_bizify_customer' => false]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('customers', 'company_scope')) {
            Schema::table('customers', fn (Blueprint $t) => $t->dropColumn('company_scope'));
        }
    }
};
