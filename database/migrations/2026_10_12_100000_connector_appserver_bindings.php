<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ENV-HUB E2 — reconciliação AppServer CADASTRAL (env_appservers, lógico) ↔ OBSERVADO (appserver_ref, Connector).
 * Vínculo EXPLÍCITO, humano, persistente. Lifecycle = SÓ active | superseded (ausência de binding = "detectado
 * não vinculado" / "cadastrado não detectado" — projeção, nunca linha artificial). Sem unbind destrutivo:
 * substituição gera nova linha (supersedes_binding_id) e marca a antiga superseded (histórico preservado).
 * NÃO usa name/port/path como identidade; NÃO auto-vincula. process_instance_id NÃO entra (é efêmero). Sem company_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('connector_appserver_bindings')) {
            Schema::create('connector_appserver_bindings', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('environment_id');
                $t->unsignedBigInteger('env_appserver_id');   // AppServer LÓGICO (cadastral)
                $t->string('connector_id', 80)->nullable();    // agente no momento do vínculo
                $t->uuid('appserver_ref');                     // identidade OBSERVADA estável (não piid)
                $t->string('status', 12)->default('active');   // active | superseded (SÓ estes)
                $t->string('reason', 300)->nullable();         // motivo do supersede
                $t->unsignedBigInteger('supersedes_binding_id')->nullable(); // cadeia de auditoria
                $t->unsignedBigInteger('bound_by')->nullable();
                $t->timestamp('bound_at')->nullable();
                $t->unsignedBigInteger('superseded_by')->nullable();
                $t->timestamp('superseded_at')->nullable();
                $t->timestamp('last_observed_at')->nullable(); // conveniência (não é status)
                $t->timestamps();
                $t->index(['environment_id', 'status']);
                $t->index('env_appserver_id');
                $t->index('appserver_ref');
            });
            // Unicidade FORTE só entre bindings ATIVOS: 1 cadastral ↔ ≤1 ref, e 1 ref ↔ ≤1 cadastral.
            DB::statement("CREATE UNIQUE INDEX cab_active_cadastral_uq ON connector_appserver_bindings (environment_id, env_appserver_id) WHERE status = 'active'");
            DB::statement("CREATE UNIQUE INDEX cab_active_ref_uq ON connector_appserver_bindings (environment_id, appserver_ref) WHERE status = 'active'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_appserver_bindings');
    }
};
