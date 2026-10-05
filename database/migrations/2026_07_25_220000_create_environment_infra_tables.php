<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cofre de Ambientes (F1b) — recursos de infra: Banco, AppServer, VPN.
 * Metadados em CLARO; a SENHA de cada um vive em env_secrets (blob cifrado no client),
 * referenciada por *_secret_id. Arquivos (.ovpn/.ini cifrados) entram na F1c.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('env_databases')) {
            Schema::create('env_databases', function (Blueprint $table) {
                $table->id();
                $table->foreignId('environment_id')->constrained('env_environments')->cascadeOnDelete();
                $table->string('engine', 20)->default('sqlserver'); // sqlserver|postgres|oracle|mysql
                $table->string('server');                            // [CLARO]
                $table->integer('port')->nullable();
                $table->string('instance')->nullable();
                $table->string('database')->nullable();
                $table->string('username')->nullable();               // [CLARO] — só a senha é segredo
                $table->foreignId('password_secret_id')->nullable()->constrained('env_secrets')->nullOnDelete();
                $table->jsonb('backup_info')->nullable();             // {estrategia, retencao, path}
                $table->boolean('always_on')->default(false);
                $table->boolean('critical')->default(false);
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->softDeletes();
                $table->timestamps();
                $table->index('environment_id');
            });
        }

        if (! Schema::hasTable('env_appservers')) {
            Schema::create('env_appservers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('environment_id')->constrained('env_environments')->cascadeOnDelete();
                $table->string('name');                               // [CLARO]
                $table->string('version')->nullable();
                $table->string('build')->nullable();
                $table->string('patch')->nullable();
                $table->string('root_path')->nullable();
                $table->integer('port')->nullable();
                // senha inline do .ini (se houver) fica em env_secrets; o ARQUIVO .ini cifrado é F1c
                $table->foreignId('ini_secret_id')->nullable()->constrained('env_secrets')->nullOnDelete();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->softDeletes();
                $table->timestamps();
                $table->index('environment_id');
            });
        }

        if (! Schema::hasTable('env_vpns')) {
            Schema::create('env_vpns', function (Blueprint $table) {
                $table->id();
                $table->foreignId('environment_id')->constrained('env_environments')->cascadeOnDelete();
                $table->string('provider', 30)->default('fortinet'); // fortinet|openvpn|...
                $table->string('server')->nullable();                 // [CLARO]
                $table->integer('port')->nullable();
                $table->string('group')->nullable();
                $table->string('username')->nullable();               // [CLARO]
                $table->foreignId('password_secret_id')->nullable()->constrained('env_secrets')->nullOnDelete();
                // ovpn_attachment_id (arquivo cifrado) entra na F1c
                $table->boolean('critical')->default(false);
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->softDeletes();
                $table->timestamps();
                $table->index('environment_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('env_vpns');
        Schema::dropIfExists('env_appservers');
        Schema::dropIfExists('env_databases');
    }
};
