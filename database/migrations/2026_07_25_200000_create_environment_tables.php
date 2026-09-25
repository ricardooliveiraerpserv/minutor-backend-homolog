<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COFRE DE AMBIENTES (F1a) — camada ADITIVA sobre o Cofre de Senhas zero-knowledge.
 *
 * Modelo HÍBRIDO: metadados NÃO-secretos em CLARO (servidor lê/indexa → dashboard,
 * busca, alertas); SEGREDOS ficam em `env_secrets.data` = ciphertext "v1." opaco,
 * cifrado NO CLIENT com a vaultKey do cliente (mesma cripto do cofre de senhas).
 *
 * Cada Cliente = 1 `vaults` type='client' (reusa a infra de chaves existente:
 * vault_members distribui a vaultKey por RSA). NADA das tabelas de segurança muda.
 */
return new class extends Migration {
    public function up(): void
    {
        // Cliente → seu vault dedicado (type='client' em `vaults`)
        if (! Schema::hasTable('env_client_vaults')) {
            Schema::create('env_client_vaults', function (Blueprint $table) {
                $table->id();
                $table->foreignId('customer_id')->unique()->constrained('customers')->cascadeOnDelete();
                $table->foreignId('vault_id')->constrained('vaults')->cascadeOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index('vault_id');
            });
        }

        if (! Schema::hasTable('env_environments')) {
            Schema::create('env_environments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
                $table->foreignId('vault_id')->constrained('vaults')->cascadeOnDelete(); // = cliente-vault
                $table->string('name');                       // 'Produção ERP' [CLARO]
                $table->string('type', 10);                   // prod|homolog|dev|dr [CLARO]
                $table->string('status', 12)->default('unknown'); // online|offline|unknown|maintenance
                $table->jsonb('inventory')->nullable();       // {so,cpu,ram,disco,portas[],servicos[]} [CLARO]
                $table->text('notes')->nullable();
                $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
                // company_id nullable; FK só se companies existir (base sem multi-empresa)
                $table->unsignedBigInteger('company_id')->nullable();
                $table->softDeletes();
                $table->timestamps();
                $table->index('customer_id');
                $table->index('vault_id');
                $table->index('type');
                $table->index('company_id');
            });
            if (Schema::hasTable('companies')) {
                Schema::table('env_environments', function (Blueprint $table) {
                    $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
                });
            }
        }

        // 1 blob por segredo. NUNCA sai em listagem; só via /reveal enforced.
        if (! Schema::hasTable('env_secrets')) {
            Schema::create('env_secrets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('environment_id')->constrained('env_environments')->cascadeOnDelete();
                $table->foreignId('vault_id')->constrained('vaults')->cascadeOnDelete(); // qual vaultKey decifra
                $table->string('kind', 20);                   // password|pfx_pass|conn_string|ovpn_inline|api_key
                $table->text('data');                         // AES-GCM(vaultKey, segredo) — [BLOB] opaco
                $table->integer('key_version')->default(1);
                $table->boolean('critical')->default(false);  // nível 4
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->softDeletes();
                $table->timestamps();
                $table->index('environment_id');
            });
        }

        if (! Schema::hasTable('env_credentials')) {
            Schema::create('env_credentials', function (Blueprint $table) {
                $table->id();
                $table->foreignId('environment_id')->constrained('env_environments')->cascadeOnDelete();
                $table->string('category', 20);               // win_admin|sql|protheus|fluig|totvs_license|ftp|smtp|azure|aws|gcp|o365|portal
                $table->string('label');                      // rótulo livre [CLARO]
                $table->string('username')->nullable();       // [CLARO] — só a SENHA é segredo
                $table->foreignId('secret_id')->nullable()->constrained('env_secrets')->nullOnDelete(); // a senha
                $table->string('url')->nullable();
                $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('last_rotated_at')->nullable();
                $table->integer('rotate_every_days')->nullable();
                $table->boolean('critical')->default(false);
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->softDeletes();
                $table->timestamps();
                $table->index('environment_id');
                $table->index(['environment_id', 'category']);
            });
        }

        // Auditoria de acesso do cofre de ambientes (molde vault_access_logs). NUNCA o valor.
        if (! Schema::hasTable('env_access_logs')) {
            Schema::create('env_access_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('environment_id')->nullable()->constrained('env_environments')->nullOnDelete();
                $table->foreignId('secret_id')->nullable()->constrained('env_secrets')->nullOnDelete();
                $table->string('item_label')->nullable();     // snapshot p/ sobreviver a delete
                $table->string('action', 30);                 // secret_reveal|secret_copy|env_create|cred_create|...
                $table->text('justification')->nullable();    // nível 4
                $table->jsonb('meta')->nullable();            // {ip, user_agent} — NUNCA valor/blob
                $table->timestamp('created_at')->useCurrent();
                $table->index('user_id');
                $table->index('environment_id');
                $table->index('action');
                $table->index('created_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('env_access_logs');
        Schema::dropIfExists('env_credentials');
        Schema::dropIfExists('env_secrets');
        Schema::dropIfExists('env_environments');
        Schema::dropIfExists('env_client_vaults');
    }
};
