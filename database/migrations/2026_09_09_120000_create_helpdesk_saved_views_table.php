<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('helpdesk_saved_views')) return;

        Schema::create('helpdesk_saved_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 120);
            $table->json('filters');            // conjunto de filtros da fila (search/status/priority/...)
            $table->boolean('is_shared')->default(false); // compartilhada = visível a todos os agentes
            $table->timestamps();
            $table->index('user_id');
            $table->index('is_shared');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('helpdesk_saved_views');
    }
};
