<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Comunicação com cliente por EMPRESA (multi-empresa): o que a ERPSERV envia não aparece na BIZIFY e vice-versa. */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('communications', 'company_id')) {
            Schema::table('communications', fn (Blueprint $t) => $t->unsignedBigInteger('company_id')->nullable()->index());
            $erp = DB::table('companies')->where('slug', 'erpserv')->value('id') ?? 1;
            DB::table('communications')->whereNull('company_id')->update(['company_id' => $erp]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('communications', 'company_id')) {
            Schema::table('communications', fn (Blueprint $t) => $t->dropColumn('company_id'));
        }
    }
};
