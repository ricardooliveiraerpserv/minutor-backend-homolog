<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM — campos extras no cadastro de contatos da empresa (reusa customer_contacts).
 * departamento, whatsapp, linkedin, influência na decisão, canal preferido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_contacts', function (Blueprint $table) {
            if (!Schema::hasColumn('customer_contacts', 'departamento'))      $table->string('departamento', 120)->nullable();
            if (!Schema::hasColumn('customer_contacts', 'whatsapp'))          $table->string('whatsapp', 40)->nullable();
            if (!Schema::hasColumn('customer_contacts', 'linkedin'))          $table->string('linkedin', 255)->nullable();
            if (!Schema::hasColumn('customer_contacts', 'influencia_decisao')) $table->string('influencia_decisao', 12)->nullable(); // alta|media|baixa
            if (!Schema::hasColumn('customer_contacts', 'canal_preferido'))   $table->string('canal_preferido', 20)->nullable();     // email|whatsapp|telefone|linkedin
        });
    }

    public function down(): void
    {
        Schema::table('customer_contacts', function (Blueprint $table) {
            foreach (['departamento', 'whatsapp', 'linkedin', 'influencia_decisao', 'canal_preferido'] as $c) {
                if (Schema::hasColumn('customer_contacts', $c)) $table->dropColumn($c);
            }
        });
    }
};
