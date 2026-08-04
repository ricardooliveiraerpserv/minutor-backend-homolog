<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tags ganham situação (ativo/inativo) para o cadastro configurável. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_tags', function (Blueprint $t) {
            if (!Schema::hasColumn('crm_tags', 'active')) $t->boolean('active')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('crm_tags', function (Blueprint $t) {
            if (Schema::hasColumn('crm_tags', 'active')) $t->dropColumn('active');
        });
    }
};
