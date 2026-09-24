<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Central de Fontes — ajustes por empresa (nível Central, NÃO o customers.active global):
// own_source = somos detentores dos fontes; hidden = oculto na lista da Central.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_doc_customer_settings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('customer_id')->unique();
            $t->boolean('own_source')->default(false);
            $t->boolean('hidden')->default(false);
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_doc_customer_settings');
    }
};
