<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parabenização de aniversários: data de nascimento no usuário + mensagens de parabéns
 * entre membros da equipe (engajamento interno). Só é possível enviar no dia do aniversário.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'birth_date')) {
            Schema::table('users', function (Blueprint $table) {
                $table->date('birth_date')->nullable()->after('matricula');
            });
        }

        Schema::create('birthday_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('to_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('message', 200)->nullable(); // permite parabéns só com emoji/sem texto
            $table->timestamps();
            $table->index(['to_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('birthday_messages');
        if (Schema::hasColumn('users', 'birth_date')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('birth_date'));
        }
    }
};
