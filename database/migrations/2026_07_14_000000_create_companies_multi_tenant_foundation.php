<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FASE 1 — Fundação multi-empresa (interno). 100% aditivo/reversível:
 *  - companies (com type internal|external p/ futuro SaaS)
 *  - company_user (vínculo N:N + papel por empresa)
 *  - users.current_company_id (empresa ativa persistida)
 *  - seed ERPSERV + BIZIFY; backfill: todo mundo → ERPSERV; is_bizify → também BIZIFY.
 * NÃO adiciona company_id em tabela de negócio ainda (isso é fase 2+), então
 * NÃO muda nenhum comportamento atual do sistema.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('companies')) {
            Schema::create('companies', function (Blueprint $t) {
                $t->id();
                $t->string('name');
                $t->string('slug')->unique();               // p/ subdomínio futuro (empresa.minutor.com)
                $t->string('cnpj', 20)->nullable();
                $t->enum('type', ['internal', 'external'])->default('internal'); // prep SaaS
                $t->enum('status', ['active', 'inactive'])->default('active');
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('company_user')) {
            Schema::create('company_user', function (Blueprint $t) {
                $t->id();
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();
                $t->foreignId('company_id')->constrained()->cascadeOnDelete();
                $t->string('role');                          // admin|coordenador|consultor|cliente|administrativo|parceiro_admin
                $t->timestamps();
                $t->unique(['user_id', 'company_id']);
            });
        }

        if (!Schema::hasColumn('users', 'current_company_id')) {
            Schema::table('users', function (Blueprint $t) {
                $t->foreignId('current_company_id')->nullable()->after('id')
                    ->constrained('companies')->nullOnDelete();
            });
        }

        // ── Seed das empresas do grupo (idempotente) ──
        $erpservId = DB::table('companies')->where('slug', 'erpserv')->value('id')
            ?? DB::table('companies')->insertGetId([
                'name' => 'ERPSERV', 'slug' => 'erpserv', 'type' => 'internal',
                'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);

        $bizifyId = DB::table('companies')->where('slug', 'bizify')->value('id')
            ?? DB::table('companies')->insertGetId([
                'name' => 'BIZIFY', 'slug' => 'bizify', 'type' => 'internal',
                'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);

        // ── Backfill: TODO usuário → ERPSERV (operadora histórica), papel = users.type ──
        DB::table('users')->orderBy('id')->select('id', 'type', 'is_bizify')->chunk(500, function ($users) use ($erpservId, $bizifyId) {
            foreach ($users as $u) {
                DB::table('company_user')->updateOrInsert(
                    ['user_id' => $u->id, 'company_id' => $erpservId],
                    ['role' => $u->type ?: 'consultor', 'updated_at' => now(), 'created_at' => now()],
                );
                // is_bizify → também vinculado à BIZIFY (formaliza o flag existente).
                if ($u->is_bizify) {
                    DB::table('company_user')->updateOrInsert(
                        ['user_id' => $u->id, 'company_id' => $bizifyId],
                        ['role' => $u->type ?: 'consultor', 'updated_at' => now(), 'created_at' => now()],
                    );
                }
            }
        });

        // Empresa ativa padrão = ERPSERV p/ quem ainda não tem.
        DB::table('users')->whereNull('current_company_id')->update(['current_company_id' => $erpservId]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'current_company_id')) {
            Schema::table('users', function (Blueprint $t) {
                $t->dropConstrainedForeignId('current_company_id');
            });
        }
        Schema::dropIfExists('company_user');
        Schema::dropIfExists('companies');
    }
};
