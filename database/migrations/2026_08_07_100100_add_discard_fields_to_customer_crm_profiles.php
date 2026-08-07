<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Descarte estruturado do lead: vincula o motivo de descarte cadastrado e agenda
 * a data de repescagem automática (repescar_em). O campo legado `lost_reason`
 * (texto) segue preenchido com o nome do motivo (snapshot) para compat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_crm_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('customer_crm_profiles', 'discard_reason_id')) {
                $table->foreignId('discard_reason_id')->nullable()
                    ->constrained('crm_discard_reasons')->nullOnDelete();
            }
            if (! Schema::hasColumn('customer_crm_profiles', 'repescar_em')) {
                $table->date('repescar_em')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('customer_crm_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('customer_crm_profiles', 'discard_reason_id')) {
                $table->dropConstrainedForeignId('discard_reason_id');
            }
            if (Schema::hasColumn('customer_crm_profiles', 'repescar_em')) {
                $table->dropColumn('repescar_em');
            }
        });
    }
};
