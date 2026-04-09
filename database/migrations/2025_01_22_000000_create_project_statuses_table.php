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
        if (Schema::hasTable('project_statuses')) {
            return;
        }

        Schema::create('project_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            // Índices
            $table->index(['is_active']);
            $table->index(['sort_order']);
            $table->index(['code']);
        });

        // Inserir dados padrão baseados nos status existentes
        DB::table('project_statuses')->insert([
            [
                'code' => 'awaiting_start',
                'name' => 'Aguardando início',
                'description' => 'Projeto aguardando início',
                'is_active' => true,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'started',
                'name' => 'Iniciado',
                'description' => 'Projeto em andamento',
                'is_active' => true,
                'sort_order' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'paused',
                'name' => 'Pausado',
                'description' => 'Projeto temporariamente pausado',
                'is_active' => true,
                'sort_order' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'cancelled',
                'name' => 'Cancelado',
                'description' => 'Projeto cancelado',
                'is_active' => true,
                'sort_order' => 4,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'finished',
                'name' => 'Encerrado',
                'description' => 'Projeto finalizado',
                'is_active' => true,
                'sort_order' => 5,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_statuses');
    }
};

