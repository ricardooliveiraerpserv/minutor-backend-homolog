<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `home_company_id` = empresa da FOLHA do funcionário (unifica o legado `is_bizify`).
 * Fonte única: a folha/fechamento é por empresa; `is_bizify` passa a ser derivado
 * (home == BIZIFY). Backfill reproduz EXATAMENTE o is_bizify de hoje → zero mudança
 * de comportamento: is_bizify=true → home=BIZIFY; senão → home=ERPSERV.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users') || Schema::hasColumn('users', 'home_company_id')) {
            return;
        }
        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('home_company_id')->nullable()->after('current_company_id')
                ->constrained('companies')->nullOnDelete();
        });

        $erp = DB::table('companies')->where('slug', 'erpserv')->value('id');
        $biz = DB::table('companies')->where('slug', 'bizify')->value('id');
        if ($erp) {
            DB::table('users')->where('is_bizify', false)->orWhereNull('is_bizify')
                ->update(['home_company_id' => $erp]);
        }
        if ($biz) {
            DB::table('users')->where('is_bizify', true)->update(['home_company_id' => $biz]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'home_company_id')) {
            Schema::table('users', function (Blueprint $t) {
                $t->dropConstrainedForeignId('home_company_id');
            });
        }
    }
};
