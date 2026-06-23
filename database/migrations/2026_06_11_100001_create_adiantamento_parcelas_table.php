<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('adiantamento_parcelas')) {
            return;
        }

        Schema::create('adiantamento_parcelas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('adiantamento_id')->constrained('adiantamentos')->cascadeOnDelete();
            $table->unsignedSmallInteger('numero');         // 1..N
            $table->string('year_month', 7);                // competência do desconto
            $table->decimal('valor', 12, 2);
            $table->timestamps();

            $table->index('adiantamento_id');
            $table->index('year_month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adiantamento_parcelas');
    }
};
