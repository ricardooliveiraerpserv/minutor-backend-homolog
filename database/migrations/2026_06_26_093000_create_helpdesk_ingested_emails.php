<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ledger de e-mails já processados pela ingestão do Help Desk. É o mecanismo de
 * dedup: como o app só tem Mail.Read (não pode marcar lido nem mover no Graph),
 * registramos cada message_id processado p/ NUNCA reprocessar o mesmo e-mail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('helpdesk_ingested_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_account_id')->constrained('helpdesk_email_accounts')->cascadeOnDelete();
            $table->string('graph_message_id', 500);
            $table->string('from_email', 190)->nullable();
            $table->string('subject', 300)->nullable();
            $table->string('action', 24);                 // ticket_created | comment_appended | ignored
            $table->string('reason', 120)->nullable();    // motivo quando ignored
            $table->foreignId('ticket_id')->nullable()->constrained('helpdesk_tickets')->nullOnDelete();
            $table->unsignedBigInteger('comment_id')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->unique(['email_account_id', 'graph_message_id'], 'hd_ingested_unique');
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('helpdesk_ingested_emails');
    }
};
