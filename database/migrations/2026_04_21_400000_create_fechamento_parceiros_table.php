<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fechamento_parceiros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained('partners');
            $table->string('year_month', 7);
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->decimal('total_horas', 10, 2)->default(0);
            $table->decimal('total_despesas', 14, 2)->default(0);
            $table->decimal('total_a_pagar', 14, 2)->default(0);
            $table->json('snapshot_consultores')->nullable();
            $table->json('snapshot_despesas')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['partner_id', 'year_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fechamento_parceiros');
    }
};
