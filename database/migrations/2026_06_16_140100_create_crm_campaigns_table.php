<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** CRM — campanhas (vínculo opcional na oportunidade). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('crm_campaigns')) {
            return;
        }
        Schema::create('crm_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_campaigns');
    }
};
