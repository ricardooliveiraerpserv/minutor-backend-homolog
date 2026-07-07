<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provedor de autenticação da conta de e-mail:
 *  - imap          → IMAP/POP3 com usuário/senha (servidores próprios, on-prem)
 *  - microsoft365  → OAuth2 / Microsoft Graph (Exchange Online / Office 365)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('helpdesk_email_accounts', function (Blueprint $table) {
            $table->string('provider', 20)->default('imap')->after('brand');
        });
    }

    public function down(): void
    {
        Schema::table('helpdesk_email_accounts', function (Blueprint $table) {
            $table->dropColumn('provider');
        });
    }
};
