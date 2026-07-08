<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Solicitante identificado automaticamente do e-mail (nome + endereço), mesmo quando o
 * remetente ainda não é um contato cadastrado — para o chamado sempre mostrar quem abriu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('helpdesk_tickets', function (Blueprint $table) {
            $table->string('requester_name', 190)->nullable()->after('customer_contact_id');
            $table->string('requester_email', 190)->nullable()->after('requester_name');
        });
    }

    public function down(): void
    {
        Schema::table('helpdesk_tickets', function (Blueprint $table) {
            $table->dropColumn(['requester_name', 'requester_email']);
        });
    }
};
