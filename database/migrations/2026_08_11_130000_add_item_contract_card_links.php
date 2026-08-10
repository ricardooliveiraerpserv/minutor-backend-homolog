<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Contrato-filho: item SaaS/Cloud vira um card próprio em "Novo Contrato".
        if (!Schema::hasColumn('contracts', 'parent_contract_id')) {
            Schema::table('contracts', function (Blueprint $table) {
                $table->unsignedBigInteger('parent_contract_id')->nullable()->after('parent_project_id');
                $table->index('parent_contract_id');
            });
        }
        // Liga o item (definição na mensalidade) ao contrato-filho que ele gerou no Kanban.
        if (!Schema::hasColumn('contract_items', 'child_contract_id')) {
            Schema::table('contract_items', function (Blueprint $table) {
                $table->unsignedBigInteger('child_contract_id')->nullable()->after('project_id');
                $table->index('child_contract_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('contracts', 'parent_contract_id')) {
            Schema::table('contracts', fn (Blueprint $t) => $t->dropColumn('parent_contract_id'));
        }
        if (Schema::hasColumn('contract_items', 'child_contract_id')) {
            Schema::table('contract_items', fn (Blueprint $t) => $t->dropColumn('child_contract_id'));
        }
    }
};
