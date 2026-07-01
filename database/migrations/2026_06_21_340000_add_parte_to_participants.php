<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Participante: lado da assinatura — contratada (ERPSERV) ou contratante (cliente). */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('crm_proposal_participants', function (Blueprint $table) {
            if (!Schema::hasColumn('crm_proposal_participants', 'parte')) {
                $table->string('parte', 16)->nullable()->after('cargo'); // contratada | contratante
            }
        });
    }

    public function down(): void
    {
        Schema::table('crm_proposal_participants', function (Blueprint $table) {
            if (Schema::hasColumn('crm_proposal_participants', 'parte')) $table->dropColumn('parte');
        });
    }
};
