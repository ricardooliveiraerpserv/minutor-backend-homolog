<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Integrações externas POR USUÁRIO (OAuth delegado). 1ª: Microsoft 365 / Outlook (agenda).
 * Tokens guardados CRIPTOGRAFADOS (cast 'encrypted' no model) e nunca retornados pela API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider', 30);                 // 'microsoft'
            $table->text('access_token')->nullable();       // criptografado
            $table->text('refresh_token')->nullable();      // criptografado
            $table->string('account_email')->nullable();    // conta conectada (exibição)
            $table->timestamp('expires_at')->nullable();     // validade do access_token
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->json('cached_events')->nullable();       // eventos sincronizados (cache p/ o calendário)
            $table->timestamps();
            // 1 integração por usuário por provider.
            $table->unique(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_integrations');
    }
};
