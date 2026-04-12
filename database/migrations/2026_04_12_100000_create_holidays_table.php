<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date')->index();
            $table->string('name');
            $table->enum('type', ['national', 'state', 'municipal', 'optional'])->default('national');
            $table->char('state', 2)->nullable()->comment('UF para feriados estaduais');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['date', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
