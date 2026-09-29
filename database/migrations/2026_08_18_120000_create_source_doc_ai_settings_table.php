<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Central de Fontes — Frente A (Governança de Custo). Configuração ADMINISTRATIVA persistente dos
 * limites de IA, editável sem deploy. Resolução em cascata global → customer → repo (mais específico
 * vence), com fallback ao config/services.php quando ausente. NÃO altera o motor (que segue lendo o
 * hard_limit por passo do config). scope_id=0 representa a linha GLOBAL (evita NULL na unicidade).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('source_doc_ai_settings')) {
            Schema::create('source_doc_ai_settings', function (Blueprint $table) {
                $table->id();
                // global | customer | repo
                $table->string('scope_type', 16)->default('global');
                $table->unsignedBigInteger('scope_id')->default(0); // 0 = global; senão customer_id ou source_repo_id
                // Limite AUTOMÁTICO por fonte (teto configurado). Operacional = auto × (1 − margem).
                $table->decimal('automatic_cost_limit_usd', 8, 4)->default(1.0000);
                $table->decimal('safety_margin_percent', 5, 2)->default(10.00); // %
                // Teto por PASSO semântico (espelha services.source_doc_ai.hard_limit_usd).
                $table->decimal('max_semantic_step_usd', 8, 4)->default(0.3000);
                // Liga a fila de aprovação quando o próximo passo estoura o limite operacional.
                $table->boolean('approval_required_above_limit')->default(true);
                // Teto MÁXIMO aprovável manualmente por fonte (nunca ultrapassável, nem por aprovação).
                $table->decimal('max_approved_cost_usd', 8, 4)->default(3.0000);
                // Acima deste valor a aprovação é obrigatória (default = automatic_cost_limit_usd).
                $table->decimal('approval_mandatory_above_usd', 8, 4)->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->unique(['scope_type', 'scope_id']);
            });
        }

        // Seed idempotente da linha GLOBAL com os defaults aprovados (1,00 / 10% / 0,30 / 3,00).
        $exists = DB::table('source_doc_ai_settings')->where('scope_type', 'global')->where('scope_id', 0)->exists();
        if (! $exists) {
            DB::table('source_doc_ai_settings')->insert([
                'scope_type' => 'global', 'scope_id' => 0,
                'automatic_cost_limit_usd' => 1.0000, 'safety_margin_percent' => 10.00,
                'max_semantic_step_usd' => 0.3000, 'approval_required_above_limit' => true,
                'max_approved_cost_usd' => 3.0000, 'approval_mandatory_above_usd' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('source_doc_ai_settings');
    }
};
