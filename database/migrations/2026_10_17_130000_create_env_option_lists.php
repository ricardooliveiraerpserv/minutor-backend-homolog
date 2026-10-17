<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Listas gerenciáveis das opções dos formulários do Cofre (categoria de credencial,
 * engine de banco, provedor de VPN, tipo de link). Metadados — NÃO são segredos, por isso
 * o cadastro vive fora do cofre (sem destravar). Built-ins são semeados sob demanda.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('env_option_lists')) {
            Schema::create('env_option_lists', function (Blueprint $table) {
                $table->id();
                $table->string('list_key', 40)->index();  // credential_category | db_engine | vpn_provider | link_kind
                $table->string('value', 60);
                $table->string('label', 120);
                $table->integer('sort')->default(0);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['list_key', 'value']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('env_option_lists');
    }
};
