<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
/** Feriado de contrato pode repetir todo ano (yearly): casa por dia/mês independente do ano. */
return new class extends Migration {
    public function up(): void {
        Schema::table('helpdesk_sla_holidays', function (Blueprint $t) {
            if (!Schema::hasColumn('helpdesk_sla_holidays', 'yearly')) $t->boolean('yearly')->default(false)->after('name');
        });
    }
    public function down(): void {
        Schema::table('helpdesk_sla_holidays', function (Blueprint $t) { $t->dropColumn('yearly'); });
    }
};
