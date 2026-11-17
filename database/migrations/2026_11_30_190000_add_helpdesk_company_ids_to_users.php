<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Empresas do HELP DESK do cliente (abas do portal / abrir chamado), independentes das empresas
 * do Minutor (company_user = seletor do topo). NULL = segue as empresas do Minutor (comportamento antigo).
 * Ex.: Promax acessa o Minutor só na ERPSERV, mas o Help Desk nas duas (ERPSERV + BIZIFY).
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'helpdesk_company_ids')) {
            Schema::table('users', fn (Blueprint $t) => $t->json('helpdesk_company_ids')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'helpdesk_company_ids')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('helpdesk_company_ids'));
        }
    }
};
