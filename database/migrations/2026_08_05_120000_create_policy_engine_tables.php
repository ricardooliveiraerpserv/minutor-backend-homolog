<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Motor de Políticas de Acesso (transversal, reutilizável por módulo).
 * CRM é o primeiro consumidor ("Política Comercial"). Genérico: nada aqui conhece o CRM.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('policy_roles')) {
            Schema::create('policy_roles', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->nullable();
                $t->string('module', 40);
                $t->string('name', 80);
                $t->boolean('is_system')->default(false);
                $t->boolean('archived')->default(false);
                $t->json('defaults')->nullable();
                $t->integer('sort_order')->default(0);
                $t->timestamps();
                $t->index(['company_id', 'module']);
                $t->unique(['company_id', 'module', 'name']);
            });
        }
        if (!Schema::hasTable('policy_assignments')) {
            Schema::create('policy_assignments', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->nullable();
                $t->unsignedBigInteger('user_id');
                $t->string('module', 40);
                $t->unsignedBigInteger('role_id')->nullable();
                $t->json('scope_ref')->nullable();
                $t->timestamps();
                $t->unique(['user_id', 'module']);
                $t->index(['company_id', 'module']);
            });
        }
        if (!Schema::hasTable('policy_overrides')) {
            Schema::create('policy_overrides', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->nullable();
                $t->unsignedBigInteger('user_id');
                $t->string('module', 40);
                $t->string('key', 80);
                $t->json('value')->nullable();
                $t->string('reason', 200)->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
                $t->unique(['user_id', 'module', 'key']);
            });
        }
        if (!Schema::hasTable('policy_audit')) {
            Schema::create('policy_audit', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->nullable();
                $t->string('module', 40);
                $t->string('target_type', 20);   // role | user
                $t->unsignedBigInteger('target_id')->nullable();
                $t->string('key', 80)->nullable();
                $t->json('from_value')->nullable();
                $t->json('to_value')->nullable();
                $t->unsignedBigInteger('actor_id')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->index(['module', 'target_type', 'target_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_audit');
        Schema::dropIfExists('policy_overrides');
        Schema::dropIfExists('policy_assignments');
        Schema::dropIfExists('policy_roles');
    }
};
