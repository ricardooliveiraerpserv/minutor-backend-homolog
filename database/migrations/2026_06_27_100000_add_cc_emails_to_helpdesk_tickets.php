<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** CC do chamado: e-mails envolvidos que recebem cópia das respostas (cadastrados ou não). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('helpdesk_tickets', function (Blueprint $table) {
            $table->json('cc_emails')->nullable()->after('requester_email');
        });
    }

    public function down(): void
    {
        Schema::table('helpdesk_tickets', function (Blueprint $table) {
            $table->dropColumn('cc_emails');
        });
    }
};
