<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cadastro de TIPOS DE CONTATO (follow-up) — antes fixo no front. Configurável em Configurações CRM.
 * crm_tasks.tipo passa a referenciar o slug deste cadastro.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('crm_contact_types')) {
            Schema::create('crm_contact_types', function (Blueprint $table) {
                $table->id();
                $table->string('nome', 80);
                $table->string('slug', 60)->unique();
                $table->unsignedInteger('ordem')->default(0);
                $table->boolean('ativo')->default(true);
                $table->timestamps();
            });

            $seed = [
                ['Ligação', 'ligacao'], ['WhatsApp', 'whatsapp'], ['E-mail', 'email'],
                ['Reunião', 'reuniao'], ['Visita', 'visita'], ['Demonstração', 'demo'],
            ];
            foreach ($seed as $i => [$nome, $slug]) {
                DB::table('crm_contact_types')->insert([
                    'nome' => $nome, 'slug' => $slug, 'ordem' => $i, 'ativo' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_contact_types');
    }
};
