<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grupos de reunião (Central de Reunião): lista nomeada de usuários internos que pode ser
 * aplicada de uma vez nos "Envolvidos" de uma reunião. Aditiva/idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('meeting_groups')) {
            Schema::create('meeting_groups', function (Blueprint $t) {
                $t->id();
                $t->string('name', 150);
                $t->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (!Schema::hasTable('meeting_group_members')) {
            Schema::create('meeting_group_members', function (Blueprint $t) {
                $t->id();
                $t->foreignId('group_id')->constrained('meeting_groups')->cascadeOnDelete();
                $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $t->timestamps();
                $t->unique(['group_id', 'user_id']);
                $t->index('user_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_group_members');
        Schema::dropIfExists('meeting_groups');
    }
};
