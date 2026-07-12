<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'inbox_email_last_sent_at')) {
                $table->timestamp('inbox_email_last_sent_at')->nullable()
                    ->comment('Última vez que o usuário recebeu digest de mensagens não lidas');
            }
            if (! Schema::hasColumn('users', 'inbox_email_disabled')) {
                $table->boolean('inbox_email_disabled')->default(false)
                    ->comment('Se true, não envia digest por email');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'inbox_email_last_sent_at')) {
                $table->dropColumn('inbox_email_last_sent_at');
            }
            if (Schema::hasColumn('users', 'inbox_email_disabled')) {
                $table->dropColumn('inbox_email_disabled');
            }
        });
    }
};
