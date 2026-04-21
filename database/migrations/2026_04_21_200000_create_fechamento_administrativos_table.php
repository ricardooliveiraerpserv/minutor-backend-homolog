<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fechamento_administrativos', function (Blueprint $table) {
            $table->id();
            $table->string('year_month', 7);            // "2026-04"
            $table->enum('status', ['open', 'closed'])->default('open');

            $table->decimal('total_custo_interno', 14, 2)->default(0);
            $table->decimal('total_custo_parceiros', 14, 2)->default(0);
            $table->decimal('total_receita', 14, 2)->default(0);
            $table->decimal('margem', 14, 2)->default(0);
            $table->decimal('margem_percentual', 8, 4)->default(0);

            $table->json('snapshot_producao')->nullable();
            $table->json('snapshot_custo')->nullable();
            $table->json('snapshot_receita')->nullable();

            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique('year_month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fechamento_administrativos');
    }
};
