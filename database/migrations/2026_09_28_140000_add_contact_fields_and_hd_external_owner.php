<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * (1) Paridade de schema: customer_contacts do dev2 estava sem colunas que o homolog/prod têm
 *     (departamento etc.) — o HD consulta essas colunas e quebrava (42703 undefined column).
 * (2) Integração Movidesk: guarda o último RESPONSÁVEL (owner) importado, p/ sincronizar o
 *     assignee sem desfazer reatribuição feita no Minutor (só reaplica quando muda no Movidesk).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_contacts', function (Blueprint $t) {
            if (!Schema::hasColumn('customer_contacts', 'departamento'))       $t->string('departamento', 120)->nullable();
            if (!Schema::hasColumn('customer_contacts', 'canal_preferido'))    $t->string('canal_preferido', 20)->nullable();
            if (!Schema::hasColumn('customer_contacts', 'influencia_decisao')) $t->string('influencia_decisao', 12)->nullable();
            if (!Schema::hasColumn('customer_contacts', 'linkedin'))           $t->string('linkedin', 255)->nullable();
            if (!Schema::hasColumn('customer_contacts', 'whatsapp'))           $t->string('whatsapp', 40)->nullable();
        });

        Schema::table('helpdesk_tickets', function (Blueprint $t) {
            if (!Schema::hasColumn('helpdesk_tickets', 'external_owner_email')) {
                $t->string('external_owner_email', 255)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('helpdesk_tickets', function (Blueprint $t) {
            $t->dropColumn('external_owner_email');
        });
        // Não removemos as colunas de customer_contacts no down (paridade com homolog/prod).
    }
};
