<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** CRM — funis (pipelines) e suas etapas (stages). Múltiplos funis desde o MVP. */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('crm_pipelines')) {
            Schema::create('crm_pipelines', function (Blueprint $table) {
                $table->id();
                $table->string('name', 80);
                $table->string('code', 40)->unique();   // novo_cliente | renovacao | expansao
                $table->integer('ordem')->default(0);
                $table->boolean('active')->default(true);
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('crm_pipeline_stages')) {
            Schema::create('crm_pipeline_stages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('pipeline_id')->constrained('crm_pipelines')->cascadeOnDelete();
                $table->string('name', 80);
                $table->integer('ordem')->default(0);
                $table->boolean('is_won')->default(false);
                $table->boolean('is_lost')->default(false);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_pipeline_stages');
        Schema::dropIfExists('crm_pipelines');
    }
};
