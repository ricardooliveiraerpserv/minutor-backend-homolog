<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permission_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('permissions')->default('[]');
            $table->timestamps();
        });

        Schema::create('permission_group_user', function (Blueprint $table) {
            $table->foreignId('permission_group_id')->constrained('permission_groups')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->primary(['permission_group_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_group_user');
        Schema::dropIfExists('permission_groups');
    }
};
