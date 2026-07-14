<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FASE 3 — company_id nas demais tabelas TRANSACIONAIS (filhas de project/contract).
 * Backfill a partir do PAI (herda a empresa correta, inclusive de projetos já movidos
 * p/ BIZIFY); fallback ERPSERV. Master data (users, customers, partners, skills, tipos,
 * permissões, nav) fica COMPARTILHADA — sem company_id. Coluna aditiva; o isolamento
 * automático segue gated pela flag `multiempresa.scoping_enabled`.
 */
return new class extends Migration
{
    /** tabela => coluna FK do pai (a tabela do pai é inferida: *_id → projects/contracts). */
    private array $byProject = [
        'excess_hour_charges', 'hour_contribution_change_logs', 'hour_contributions',
        'manual_reajustes', 'movidesk_organizations', 'on_demand_invoiced_months',
        'project_change_logs', 'project_consultant_groups', 'project_consultant_real_projects',
        'project_consultants', 'project_contacts', 'project_coordinator_percentage_logs',
        'project_coordinators', 'project_kanban_logs', 'project_message_participants',
        'project_messages', 'project_monthly_consumptions', 'project_open_periods',
        'project_required_skills', 'project_sold_hours_history', 'project_user_reads',
        'ticket_initial_balances',
    ];
    private array $byContract = [
        'contract_contacts', 'contract_events', 'contract_events_archive', 'contract_kanban_logs',
        'contract_messages', 'contract_requests', 'contract_sequences', 'contract_user_reads',
        'contract_value_changes',
    ];
    // Têm os dois: backfill por project_id, e o que sobrar por contract_id.
    private array $byBoth = ['contract_flow_snapshots', 'conversations', 'operational_feed'];

    public function up(): void
    {
        $erpservId = DB::table('companies')->where('slug', 'erpserv')->value('id');
        $all = array_merge($this->byProject, $this->byContract, $this->byBoth);

        foreach ($all as $table) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'company_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
                    $t->index('company_id');
                });
            }
        }

        // Backfill a partir do pai.
        foreach ($this->byProject as $table) {
            if (Schema::hasTable($table)) {
                DB::statement("UPDATE {$table} c SET company_id = p.company_id FROM projects p WHERE p.id = c.project_id AND c.company_id IS NULL");
            }
        }
        foreach (array_merge($this->byContract, $this->byBoth) as $table) {
            if (Schema::hasTable($table) && $table !== 'operational_feed' && $table !== 'contract_flow_snapshots' && $table !== 'conversations') {
                DB::statement("UPDATE {$table} c SET company_id = ct.company_id FROM contracts ct WHERE ct.id = c.contract_id AND c.company_id IS NULL");
            }
        }
        // byBoth: primeiro por project, depois o resto por contract.
        foreach ($this->byBoth as $table) {
            if (Schema::hasTable($table)) {
                DB::statement("UPDATE {$table} c SET company_id = p.company_id FROM projects p WHERE p.id = c.project_id AND c.company_id IS NULL");
                DB::statement("UPDATE {$table} c SET company_id = ct.company_id FROM contracts ct WHERE ct.id = c.contract_id AND c.company_id IS NULL");
            }
        }
        // Fallback: qualquer sobra → ERPSERV.
        if ($erpservId) {
            foreach ($all as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->whereNull('company_id')->update(['company_id' => $erpservId]);
                }
            }
        }
    }

    public function down(): void
    {
        $all = array_merge($this->byProject, $this->byContract, $this->byBoth);
        foreach ($all as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'company_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropConstrainedForeignId('company_id');
                });
            }
        }
    }
};
