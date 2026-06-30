<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contas de E-mail do Help Desk — caixas de RECEBIMENTO (IMAP/POP) que transformam
 * solicitações recebidas por e-mail em chamados. (Envio/SMTP pode ser usado depois.)
 * Senha guardada CRIPTOGRAFADA (cast encrypted) e nunca retornada pela API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('helpdesk_email_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 190);
            $table->string('brand', 120)->nullable();              // Marca (rótulo do remetente)
            // ── Recebimento (IMAP/POP) ──
            $table->boolean('receive_enabled')->default(true);
            $table->string('protocol', 10)->default('imap');       // imap | pop3
            $table->string('host', 190)->nullable();
            $table->integer('port')->nullable();
            $table->string('encryption', 8)->default('ssl');        // ssl | tls | none
            $table->string('username', 190)->nullable();
            $table->text('password')->nullable();                   // criptografada (cast encrypted)
            $table->string('inbox', 60)->default('INBOX');
            // ── Envio (SMTP) ──
            $table->string('smtp_host', 190)->nullable();
            $table->integer('smtp_port')->nullable();
            $table->string('smtp_encryption', 8)->nullable();
            // demais opções de recebimento/envio (toggles, radios, datas)
            $table->json('settings')->nullable();
            $table->unsignedBigInteger('default_team_id')->nullable(); // fila p/ chamados recebidos
            $table->boolean('enabled')->default(true);
            $table->string('last_status', 12)->nullable();          // connected | failed (resultado do último teste)
            $table->text('last_error')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('helpdesk_email_accounts');
    }
};
