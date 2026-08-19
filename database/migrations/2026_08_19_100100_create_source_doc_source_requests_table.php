<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Central de Fontes — solicitações de fonte (pedir provisionamento de um repositório/fonte).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_doc_source_requests', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->string('repository')->nullable();
            $t->text('note')->nullable();
            $t->string('status')->default('open'); // open | provisioned | rejected
            $t->unsignedBigInteger('requested_by')->nullable();
            $t->timestamps();
            $t->index('customer_id');
            $t->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_doc_source_requests');
    }
};
