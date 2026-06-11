<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fechamento_diretoria_itens')) {
            return;
        }

        Schema::create('fechamento_diretoria_itens', function (Blueprint $table) {
            $table->id();
            $table->string('year_month', 7);            // competência AAAA-MM
            $table->unsignedInteger('ordem')->default(0);
            $table->string('cooperativa')->nullable();
            $table->decimal('repasse', 14, 2)->nullable();
            $table->decimal('taxa_inss', 14, 2)->nullable();
            $table->decimal('valor_receber', 14, 2)->nullable();
            $table->text('observacao')->nullable();
            $table->boolean('destaque')->default(false); // linha de destaque (verde no relatório)
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('year_month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fechamento_diretoria_itens');
    }
};
