<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('movidesk_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_id')->unique()->index();
            $table->json('solicitante')->nullable(); // {name, email, organization}
            $table->string('categoria')->nullable();
            $table->string('urgencia')->nullable();
            $table->json('responsavel')->nullable(); // {name, email}
            $table->string('nivel')->nullable();
            $table->string('servico')->nullable();
            $table->string('titulo')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });

        // Garantir que a tabela use utf8mb4 para suportar caracteres especiais e acentos (apenas MySQL)
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE movidesk_tickets CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('movidesk_tickets');
    }
};
