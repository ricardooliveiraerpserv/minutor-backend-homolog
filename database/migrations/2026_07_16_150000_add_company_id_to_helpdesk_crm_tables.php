<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-empresa para HELP DESK e CRM: adiciona company_id (nullable + FK + índice)
 * nas ENTIDADES RAIZ desses módulos e faz backfill de TODO o existente → ERPSERV.
 * As tabelas-filhas (comentários/eventos/campos/metas) são segregadas via o pai
 * já escopado; junções puras ficam de fora. Com os models usando BelongsToCompany,
 * o CompanyScope passa a segregar HD/CRM por empresa (BIZIFY separado).
 *
 * Idempotente. Inerte de fato até MULTIEMPRESA_SCOPING=true (o scoping é gated).
 */
return new class extends Migration
{
    private array $tables = [
        // Help Desk (raiz)
        'helpdesk_tickets', 'helpdesk_services', 'helpdesk_categories', 'helpdesk_statuses',
        'helpdesk_tags', 'helpdesk_teams', 'helpdesk_sla_policies', 'helpdesk_triggers',
        'helpdesk_forms', 'helpdesk_access_profiles', 'helpdesk_association_rules',
        'helpdesk_comm_template', 'helpdesk_ticket_justifications', 'helpdesk_kb_categories',
        'helpdesk_kb_articles', 'helpdesk_email_accounts', 'helpdesk_ingested_emails',
        'helpdesk_finalize_operations',
        // CRM (raiz)
        'crm_opportunities', 'crm_pipelines', 'crm_pipeline_stages', 'crm_products',
        'crm_proposals', 'crm_tasks', 'crm_contact_types', 'crm_lead_sources',
        'crm_loss_reasons', 'crm_tags', 'crm_campaigns', 'crm_sales_targets',
        'crm_saved_filters', 'crm_signature_profiles', 'crm_stage_automations',
        'crm_forecast_snapshots', 'crm_account_health_snapshots',
    ];

    public function up(): void
    {
        $erpservId = DB::table('companies')->where('slug', 'erpserv')->value('id');

        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'company_id')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('company_id')->nullable()->after('id')
                    ->constrained('companies')->nullOnDelete();
                $t->index('company_id');
            });
            if ($erpservId) {
                DB::table($table)->whereNull('company_id')->update(['company_id' => $erpservId]);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'company_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropConstrainedForeignId('company_id');
                });
            }
        }
    }
};
