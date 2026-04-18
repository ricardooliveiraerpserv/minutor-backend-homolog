<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movidesk_agents', function (Blueprint $table) {
            $table->id();
            $table->string('movidesk_id')->unique();
            $table->string('name');
            $table->string('email')->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->string('team')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movidesk_agents');
    }
};
