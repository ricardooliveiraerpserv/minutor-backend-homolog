<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM — tags (rótulos) reutilizáveis + pivot empresa↔tag.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('crm_tags')) {
            Schema::create('crm_tags', function (Blueprint $table) {
                $table->id();
                $table->string('name', 60)->unique();
                $table->string('color', 16)->nullable();
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('customer_tag')) {
            Schema::create('customer_tag', function (Blueprint $table) {
                $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
                $table->foreignId('crm_tag_id')->constrained('crm_tags')->cascadeOnDelete();
                $table->primary(['customer_id', 'crm_tag_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_tag');
        Schema::dropIfExists('crm_tags');
    }
};
