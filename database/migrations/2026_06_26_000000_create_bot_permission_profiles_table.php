<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permissões padrão do BOT por perfil (admin/coordenador/consultor/cliente/...).
 *
 * Ao criar um user novo, esses defaults são aplicados conforme o users.type.
 * Admin pode editar pela aba "Permissões padrão" em /configuracoes/bot-minutor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_permission_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('profile_type', 40)->unique();
            $table->string('label', 80);
            $table->text('description')->nullable();
            $table->boolean('can_use_bot')->default(true);
            $table->jsonb('allowed_scopes')->nullable();
            $table->string('visibility', 16)->default('self');
            $table->jsonb('scope_overrides')->nullable();
            $table->timestamps();
        });

        $ALL = ['customer', 'project', 'contract', 'financial', 'billing', 'payroll', 'bankhours', 'approvals', 'overview', 'support'];
        $defaults = [
            [
                'profile_type' => 'admin',
                'label' => 'Administrador',
                'description' => 'Acesso total ao BOT. Vê dados de qualquer consultor e cliente.',
                'can_use_bot' => true,
                'allowed_scopes' => null,
                'visibility' => 'all',
                'scope_overrides' => null,
            ],
            [
                'profile_type' => 'administrativo',
                'label' => 'Administrativo',
                'description' => 'Mesma visão do admin (financeiro/folha completo).',
                'can_use_bot' => true,
                'allowed_scopes' => null,
                'visibility' => 'all',
                'scope_overrides' => null,
            ],
            [
                'profile_type' => 'coordenador',
                'label' => 'Coordenador',
                'description' => 'Vê dados da equipe que coordena (consultores e clientes).',
                'can_use_bot' => true,
                'allowed_scopes' => $ALL,
                'visibility' => 'team',
                'scope_overrides' => null,
            ],
            [
                'profile_type' => 'consultor',
                'label' => 'Consultor',
                'description' => 'Vê apenas dados próprios. Sem folha/banco de horas/billing por padrão.',
                'can_use_bot' => true,
                'allowed_scopes' => ['customer', 'project', 'financial', 'approvals', 'support'],
                'visibility' => 'self',
                'scope_overrides' => null,
            ],
            [
                'profile_type' => 'cliente',
                'label' => 'Cliente externo',
                'description' => 'Vê apenas dados do próprio cliente. Sem dados financeiros ou de folha.',
                'can_use_bot' => false,
                'allowed_scopes' => ['customer', 'project', 'support'],
                'visibility' => 'self',
                'scope_overrides' => null,
            ],
            [
                'profile_type' => 'parceiro_admin',
                'label' => 'Parceiro',
                'description' => 'Vê consultores e contratos do próprio parceiro.',
                'can_use_bot' => false,
                'allowed_scopes' => ['customer', 'project', 'contract'],
                'visibility' => 'self',
                'scope_overrides' => null,
            ],
        ];

        $now = now();
        foreach ($defaults as $row) {
            DB::table('bot_permission_profiles')->insert(array_merge($row, [
                'allowed_scopes'   => $row['allowed_scopes']   !== null ? json_encode($row['allowed_scopes'])   : null,
                'scope_overrides'  => $row['scope_overrides']  !== null ? json_encode($row['scope_overrides'])  : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_permission_profiles');
    }
};
