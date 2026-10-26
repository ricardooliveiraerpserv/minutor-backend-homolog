<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico de LOGINS (um registro por autenticação bem-sucedida) + carimbo rápido
 * users.last_login_at. Alimenta a tela "Acessos" (quem já acessou, último acesso, etc.).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('login_logs')) {
            Schema::create('login_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('ip', 64)->nullable();
                $table->string('user_agent', 400)->nullable();
                $table->string('device', 120)->nullable(); // device_name do token (web/mobile)
                $table->timestamp('created_at')->nullable()->index();
                $table->index(['user_id', 'created_at']);
            });
        }
        if (! Schema::hasColumn('users', 'last_login_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('last_login_at')->nullable()->after('enabled');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('login_logs');
        if (Schema::hasColumn('users', 'last_login_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('last_login_at');
            });
        }
    }
};
