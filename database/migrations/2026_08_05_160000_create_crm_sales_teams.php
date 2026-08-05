<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Equipe de Vendas do CRM: materializa o escopo "Equipe"/gestor da Política Comercial.
 * Uma equipe tem um gestor (manager_id) e membros (pivot). Com isso, os gates com escopo
 * 'team' deixam de "errar aberto" e passam a filtrar por responsáveis da equipe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('crm_sales_teams')) {
            Schema::create('crm_sales_teams', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->nullable()->index();
                $t->string('name');
                $t->unsignedBigInteger('manager_id')->nullable()->index(); // gestor
                $t->boolean('active')->default(true);
                $t->timestamps();
            });
        }
        if (!Schema::hasTable('crm_sales_team_user')) {
            Schema::create('crm_sales_team_user', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('team_id')->index();
                $t->unsignedBigInteger('user_id')->index();
                $t->timestamps();
                $t->unique(['team_id', 'user_id'], 'crm_team_user_uq');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_sales_team_user');
        Schema::dropIfExists('crm_sales_teams');
    }
};
