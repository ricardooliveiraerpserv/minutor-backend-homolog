<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Escrow corporativo (Caminho A — zero-knowledge mantido):
 * - vault_org_recovery: UMA chave de recuperação da EMPRESA (par RSA). A pública fica aqui;
 *   a privada fica CIFRADA com a "Chave de Recuperação da Empresa" (gerada e guardada pelos
 *   admins — o servidor nunca vê essa chave em claro). Permite reabrir pastas de cliente
 *   mesmo que todos percam master password + recovery key.
 * - env_client_vaults.escrow_encrypted_vault_key: cópia do vaultKey da pasta cifrada para a
 *   chave pública da empresa. Com a privada da empresa (recuperada pela chave de recuperação),
 *   um admin reabre e re-compartilha a pasta.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vault_org_recovery')) {
            Schema::create('vault_org_recovery', function (Blueprint $table) {
                $table->id();
                $table->text('public_key');                 // RSA pública da empresa (base64 SPKI)
                $table->text('encrypted_private_key');       // RSA privada cifrada com a Chave de Recuperação da Empresa (AES-GCM)
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('env_client_vaults') && ! Schema::hasColumn('env_client_vaults', 'escrow_encrypted_vault_key')) {
            Schema::table('env_client_vaults', function (Blueprint $table) {
                $table->text('escrow_encrypted_vault_key')->nullable()->after('vault_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('env_client_vaults') && Schema::hasColumn('env_client_vaults', 'escrow_encrypted_vault_key')) {
            Schema::table('env_client_vaults', function (Blueprint $table) {
                $table->dropColumn('escrow_encrypted_vault_key');
            });
        }
        Schema::dropIfExists('vault_org_recovery');
    }
};
