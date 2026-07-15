<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-empresa: marcador de cliente Bizify. A tela de Clientes com Bizify ativo lista
 * só clientes Bizify. Cliente entra na Bizify por ter projeto Bizify (backfill) OU por
 * ser "vinculado" do cadastro geral (seta a flag). Cliente segue compartilhado; ERPSERV
 * continua vendo todos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'is_bizify_customer')) {
                $table->boolean('is_bizify_customer')->default(false)->after('executive_bizify_id');
            }
        });

        // Backfill: quem já tem projeto da empresa Bizify (slug 'bizify') vira cliente Bizify.
        $bizifyId = DB::table('companies')->where('slug', 'bizify')->value('id');
        if ($bizifyId) {
            DB::table('customers')->whereIn('id', function ($q) use ($bizifyId) {
                $q->select('customer_id')->from('projects')
                  ->where('company_id', $bizifyId)->whereNull('deleted_at');
            })->update(['is_bizify_customer' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'is_bizify_customer')) {
                $table->dropColumn('is_bizify_customer');
            }
        });
    }
};
