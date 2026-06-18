<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * CRM — criação de oportunidade: origem CADASTRADA (FK crm_lead_sources, fim do texto livre)
 * e contato principal (FK customer_contacts). Ambos nullable no banco (registros antigos
 * permanecem válidos); obrigatoriedade só na criação nova, via validação.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_opportunities', function (Blueprint $table) {
            if (!Schema::hasColumn('crm_opportunities', 'lead_source_id')) {
                $table->foreignId('lead_source_id')->nullable()->after('origem')->constrained('crm_lead_sources')->nullOnDelete();
            }
            if (!Schema::hasColumn('crm_opportunities', 'customer_contact_id')) {
                $table->foreignId('customer_contact_id')->nullable()->after('customer_id')->constrained('customer_contacts')->nullOnDelete();
            }
        });

        // Backfill leve: casa origem (texto) → lead_source_id por nome (case-insensitive).
        foreach (DB::table('crm_lead_sources')->get() as $src) {
            DB::table('crm_opportunities')
                ->whereNull('lead_source_id')
                ->whereRaw('lower(trim(origem)) = ?', [mb_strtolower(trim($src->name))])
                ->update(['lead_source_id' => $src->id]);
        }
    }

    public function down(): void
    {
        Schema::table('crm_opportunities', function (Blueprint $table) {
            if (Schema::hasColumn('crm_opportunities', 'lead_source_id')) $table->dropConstrainedForeignId('lead_source_id');
            if (Schema::hasColumn('crm_opportunities', 'customer_contact_id')) $table->dropConstrainedForeignId('customer_contact_id');
        });
    }
};
