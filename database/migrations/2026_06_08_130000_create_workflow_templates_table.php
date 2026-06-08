<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Override do modelo (título + texto) por workflow. Ausência = default do registry.
        Schema::create('workflow_templates', function (Blueprint $t) {
            $t->id();
            $t->string('workflow_key', 80)->unique();
            $t->string('subject', 255)->nullable();
            $t->text('body')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_templates');
    }
};
