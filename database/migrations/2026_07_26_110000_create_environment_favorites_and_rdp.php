<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F2 — Acesso Rápido + Favoritos (aditivo).
 *  - env_favorites: ambiente marcado como favorito por usuário.
 *  - env_environments.rdp_host/rdp_port: alvo do "Abrir RDP" do launcher (metadado CLARO).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('env_favorites')) {
            Schema::create('env_favorites', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('environment_id')->constrained('env_environments')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['user_id', 'environment_id']);
                $table->index('environment_id');
            });
        }

        Schema::table('env_environments', function (Blueprint $table) {
            if (! Schema::hasColumn('env_environments', 'rdp_host')) {
                $table->string('rdp_host')->nullable();          // ex.: 10.0.0.5 ou srv.cliente.com [CLARO]
            }
            if (! Schema::hasColumn('env_environments', 'rdp_port')) {
                $table->unsignedSmallInteger('rdp_port')->nullable(); // default RDP = 3389
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('env_favorites');
        Schema::table('env_environments', function (Blueprint $table) {
            if (Schema::hasColumn('env_environments', 'rdp_host')) {
                $table->dropColumn('rdp_host');
            }
            if (Schema::hasColumn('env_environments', 'rdp_port')) {
                $table->dropColumn('rdp_port');
            }
        });
    }
};
