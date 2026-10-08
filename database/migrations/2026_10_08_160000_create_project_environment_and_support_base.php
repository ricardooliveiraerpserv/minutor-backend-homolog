<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liga PROJETO ↔ AMBIENTE (cofre de ambientes) e marca a BASE da SUSTENTAÇÃO.
 * - project_environment: ambiente(s) do cliente em que o projeto está sendo desenvolvido.
 * - env_environments.is_support_base: ambiente que a sustentação do cliente utiliza.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('project_environment')) {
            Schema::create('project_environment', function (Blueprint $table) {
                $table->id();
                $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
                $table->foreignId('environment_id')->constrained('env_environments')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['project_id', 'environment_id']);
            });
        }

        Schema::table('env_environments', function (Blueprint $table) {
            if (! Schema::hasColumn('env_environments', 'is_support_base')) {
                $table->boolean('is_support_base')->default(false)->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_environment');
        Schema::table('env_environments', function (Blueprint $table) {
            if (Schema::hasColumn('env_environments', 'is_support_base')) {
                $table->dropColumn('is_support_base');
            }
        });
    }
};
