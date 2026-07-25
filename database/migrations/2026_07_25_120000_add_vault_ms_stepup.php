<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cofre: 2FA via Microsoft Entra (substitui o TOTP quando VAULT_2FA_DRIVER=microsoft).
 * ms_oid = conta Entra pinada no 1º step-up; stepup_token_* = prova efêmera (5 min)
 * emitida pelo callback OAuth e exigida no unlock/operações destrutivas.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('vault_user_keys', function (Blueprint $table) {
            if (! Schema::hasColumn('vault_user_keys', 'ms_oid')) {
                $table->string('ms_oid', 64)->nullable();
            }
            if (! Schema::hasColumn('vault_user_keys', 'stepup_token_hash')) {
                $table->text('stepup_token_hash')->nullable();
            }
            if (! Schema::hasColumn('vault_user_keys', 'stepup_token_expires_at')) {
                $table->timestamp('stepup_token_expires_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('vault_user_keys', function (Blueprint $table) {
            $table->dropColumn(['ms_oid', 'stepup_token_hash', 'stepup_token_expires_at']);
        });
    }
};
