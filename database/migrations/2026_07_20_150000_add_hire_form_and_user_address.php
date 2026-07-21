<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * (1) Formulário estruturado ("Script de passagem") no card de contratação, em
 *     JSON, no lugar das anotações em texto livre.
 * (2) Endereço no cadastro de usuário (CEP + logradouro/número/complemento/
 *     bairro). city/state já existem. Alimentado pela contratação e pelo próprio
 *     cadastro (busca automática por CEP).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skill_hire_cards', function (Blueprint $table) {
            $table->json('form')->nullable()->after('notes');
        });

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'cep')) {
                $table->string('cep', 9)->nullable()->after('state');
            }
            if (! Schema::hasColumn('users', 'address_street')) {
                $table->string('address_street', 200)->nullable()->after('cep');
            }
            if (! Schema::hasColumn('users', 'address_number')) {
                $table->string('address_number', 20)->nullable()->after('address_street');
            }
            if (! Schema::hasColumn('users', 'address_complement')) {
                $table->string('address_complement', 120)->nullable()->after('address_number');
            }
            if (! Schema::hasColumn('users', 'neighborhood')) {
                $table->string('neighborhood', 120)->nullable()->after('address_complement');
            }
        });
    }

    public function down(): void
    {
        Schema::table('skill_hire_cards', function (Blueprint $table) {
            $table->dropColumn('form');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['cep', 'address_street', 'address_number', 'address_complement', 'neighborhood']);
        });
    }
};
