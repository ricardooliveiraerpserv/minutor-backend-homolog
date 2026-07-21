<?php

use App\Services\SkillSurveyService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Configuração dos campos cadastrais do formulário por tipo (internal/partner/
 * candidate). Torna o schema editável pelo admin (rotina de Configuração de
 * Formulários) — antes era fixo em SkillSurveyService::CADASTRAL_SCHEMA, que
 * agora vira só o DEFAULT/fallback + fonte do seed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skill_form_configs', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->unique()->comment('internal | partner | candidate');
            $table->json('fields');
            $table->timestamps();
        });

        foreach (SkillSurveyService::CADASTRAL_SCHEMA as $type => $fields) {
            DB::table('skill_form_configs')->insert([
                'type' => $type,
                'fields' => json_encode($fields, JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_form_configs');
    }
};
