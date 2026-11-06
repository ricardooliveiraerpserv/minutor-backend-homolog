<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot N:N entre áreas de OKR e usuários vinculados. Espelha consultant_group_user.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('okr_area_user')) {
            Schema::create('okr_area_user', function (Blueprint $t) {
                $t->id();
                $t->foreignId('okr_area_id')->constrained('okr_areas')->cascadeOnDelete();
                $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $t->timestamps();

                $t->unique(['okr_area_id', 'user_id']);
                $t->index('okr_area_id');
                $t->index('user_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('okr_area_user');
    }
};
