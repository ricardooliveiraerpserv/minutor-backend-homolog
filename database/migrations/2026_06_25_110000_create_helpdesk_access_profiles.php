<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Perfis de Acesso do Help Desk (estilo Movidesk). Cada perfil é p/ AGENTE ou CLIENTE,
 * pode ser o padrão do tipo, habilitado/desabilitado, e carrega um conjunto FLEXÍVEL de
 * permissões (JSON) — só as aplicáveis ao nosso HD (cresce sem migration). Usuário é
 * vinculado a um perfil (enforcement entra incrementalmente).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('helpdesk_access_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('kind', 12)->default('agent'); // agent | cliente
            $table->boolean('is_default')->default(false); // padrão do tipo (1 por kind)
            $table->boolean('enabled')->default(true);
            $table->json('permissions')->nullable();        // conjunto de flags aplicáveis
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['kind', 'enabled']);
        });

        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'helpdesk_access_profile_id')) {
                $table->unsignedBigInteger('helpdesk_access_profile_id')->nullable()->after('id');
            }
        });

        // Perfis padrão iniciais (pelo menos um de cada tipo ativo).
        $now = now();
        foreach ([['Agentes', 'agent'], ['Administradores', 'agent'], ['Clientes', 'cliente']] as $i => [$name, $kind]) {
            if (!DB::table('helpdesk_access_profiles')->where('name', $name)->where('kind', $kind)->exists()) {
                DB::table('helpdesk_access_profiles')->insert([
                    'name' => $name, 'kind' => $kind, 'is_default' => $name === 'Agentes' || $name === 'Clientes',
                    'enabled' => true, 'permissions' => null, 'sort_order' => ($i + 1) * 10,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'helpdesk_access_profile_id')) $table->dropColumn('helpdesk_access_profile_id');
        });
        Schema::dropIfExists('helpdesk_access_profiles');
    }
};
