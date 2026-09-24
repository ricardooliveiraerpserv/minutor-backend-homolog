<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Classificação do chamado pelo Catálogo de Serviços. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('helpdesk_tickets', function (Blueprint $table) {
            if (!Schema::hasColumn('helpdesk_tickets', 'service_id')) {
                $table->unsignedBigInteger('service_id')->nullable()->after('category_id');
                $table->index('service_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('helpdesk_tickets', function (Blueprint $table) {
            if (Schema::hasColumn('helpdesk_tickets', 'service_id')) {
                $table->dropColumn('service_id');
            }
        });
    }
};
