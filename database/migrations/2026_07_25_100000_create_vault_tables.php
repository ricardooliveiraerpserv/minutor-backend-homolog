<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cofre de Senhas (zero-knowledge).
 *
 * IMPORTANTE: os campos encrypted_* e data são blobs cifrados NO CLIENT
 * (AES-GCM "v1.<b64 iv>.<b64 ct||tag>" / RSA-OAEP "v1r.<b64 ct>").
 * O servidor os trata como TEXT opaco — NUNCA aplicar cast 'encrypted'
 * nem tentar decifrar. A única exceção server-side é totp_secret (2FA).
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('vault_user_keys')) {
            Schema::create('vault_user_keys', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
                // Parâmetros Argon2id usados no client (permite evoluir sem quebrar unlock)
                $table->smallInteger('kdf_iterations')->default(3);
                $table->integer('kdf_memory')->default(65536); // KiB
                $table->smallInteger('kdf_parallelism')->default(4);
                // bcrypt do auth_hash derivado no client (one-way; não deriva a master key)
                $table->text('auth_hash')->nullable();
                // AES-GCM(masterKey, userSymKey)
                $table->text('encrypted_symmetric_key')->nullable();
                // SPKI base64 (claro — usada pelos outros pra compartilhar cofres)
                $table->text('public_key')->nullable();
                // AES-GCM(userSymKey, PKCS8 da privada RSA)
                $table->text('encrypted_private_key')->nullable();
                // AES-GCM(recoveryKey, userSymKey) — caminho de recuperação
                $table->text('recovery_symmetric_key')->nullable();
                // 2FA server-side (cast 'encrypted' no Model)
                $table->text('totp_secret')->nullable();
                $table->timestamp('totp_confirmed_at')->nullable();
                // Anti-replay RFC 6238: último timestep aceito
                $table->bigInteger('totp_last_timestep')->nullable();
                // Token efêmero do fluxo de recovery (bcrypt; evita consumir 2 códigos TOTP)
                $table->text('recovery_token_hash')->nullable();
                $table->timestamp('recovery_token_expires_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('vaults')) {
            Schema::create('vaults', function (Blueprint $table) {
                $table->id();
                // company_id nullable; FK adicionada só se a tabela companies existir
                // (multi-empresa pode não estar rolada em todos os ambientes/bases locais).
                $table->unsignedBigInteger('company_id')->nullable();
                $table->string('type', 20); // 'personal' | 'shared'
                $table->string('name'); // claro (listagem/busca)
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->integer('key_version')->default(1);
                $table->boolean('pending_rotation')->default(false);
                $table->timestamps();

                $table->index('type');
                $table->index('created_by');
                $table->index('company_id');
            });
            if (Schema::hasTable('companies')) {
                Schema::table('vaults', function (Blueprint $table) {
                    $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
                });
            }
            // Um único cofre pessoal por usuário
            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS vaults_personal_owner_unique ON vaults (created_by) WHERE type = 'personal'");
        }

        if (! Schema::hasTable('vault_members')) {
            Schema::create('vault_members', function (Blueprint $table) {
                $table->id();
                $table->foreignId('vault_id')->constrained('vaults')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('role', 10); // 'admin' | 'write' | 'read'
                // RSA-OAEP(pubKey do membro, vaultKey)
                $table->text('encrypted_vault_key');
                $table->integer('key_version')->default(1);
                $table->timestamps();

                $table->unique(['vault_id', 'user_id']);
                $table->index('vault_id');
                $table->index('user_id');
            });
        }

        if (! Schema::hasTable('vault_items')) {
            Schema::create('vault_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('vault_id')->constrained('vaults')->cascadeOnDelete();
                $table->string('type', 20)->default('login'); // futuro: 'note', 'card'
                $table->string('name'); // CLARO — busca server-side; o resto vai cifrado em data
                // AES-GCM(vaultKey, JSON {username, password, url, notes, totp_seed, reprompt})
                $table->text('data');
                $table->integer('key_version')->default(1);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->softDeletes();
                $table->timestamps();

                $table->index('vault_id');
                $table->index(['vault_id', 'name']);
                $table->index('deleted_at');
            });
        }

        if (! Schema::hasTable('vault_access_logs')) {
            Schema::create('vault_access_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('vault_id')->nullable()->constrained('vaults')->nullOnDelete();
                $table->foreignId('item_id')->nullable()->constrained('vault_items')->nullOnDelete();
                // Snapshot pro log sobreviver ao delete do item
                $table->string('item_name')->nullable();
                // profile_setup | unlock_success | unlock_failed | recovery_used |
                // master_password_change | vault_create/update/delete |
                // member_add/remove | key_rotate | item_create/update/delete/restore |
                // item_reveal | item_copy
                $table->string('action', 30);
                // {ip, user_agent, target_user_id?} — NUNCA conteúdo/blobs
                $table->jsonb('meta')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index('user_id');
                $table->index('vault_id');
                $table->index('action');
                $table->index('created_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_access_logs');
        Schema::dropIfExists('vault_items');
        Schema::dropIfExists('vault_members');
        Schema::dropIfExists('vaults');
        Schema::dropIfExists('vault_user_keys');
    }
};
