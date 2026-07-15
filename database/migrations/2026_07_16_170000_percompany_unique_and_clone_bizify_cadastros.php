<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HOMOLOG: cadastros de HD/CRM por-empresa exigem que os uniques que eram GLOBAIS
 * (code/key/name/slug/email...) virem COMPOSTOS com company_id — senão a BIZIFY não
 * pode ter os mesmos códigos da ERPSERV (clone/seed batem no unique → 422 no CRM,
 * kanban sem filas no HD). Aqui: (1) troca os uniques globais por compostos e
 * (2) re-roda o clone ERPSERV→BIZIFY (idempotente). Supersede o 160000 (que podia
 * ter falhado por causa do unique). Gated ao homolog; try/catch não bloqueia deploy.
 */
return new class extends Migration
{
    /** [tabela, nome do unique global antigo, colunas do novo composto]. */
    private array $uniqueFix = [
        ['crm_contact_types',      'crm_contact_types_slug_unique',        ['company_id', 'slug']],
        ['crm_lead_sources',       'crm_lead_sources_name_unique',         ['company_id', 'name']],
        ['crm_loss_reasons',       'crm_loss_reasons_name_unique',         ['company_id', 'name']],
        ['crm_pipelines',          'crm_pipelines_code_unique',            ['company_id', 'code']],
        ['crm_signature_profiles', 'crm_signature_profiles_email_unique',  ['company_id', 'email']],
        ['crm_tags',               'crm_tags_name_unique',                 ['company_id', 'name']],
        ['helpdesk_statuses',      'helpdesk_statuses_key_unique',         ['company_id', 'key']],
        ['helpdesk_tags',          'helpdesk_tags_name_unique',            ['company_id', 'name']],
        ['helpdesk_tickets',       'helpdesk_tickets_ticket_number_unique', ['company_id', 'ticket_number']],
    ];

    private array $order = [
        'helpdesk_statuses', 'helpdesk_tags', 'helpdesk_access_profiles', 'helpdesk_comm_template',
        'helpdesk_ticket_justifications', 'helpdesk_categories', 'helpdesk_services', 'helpdesk_teams',
        'helpdesk_sla_policies', 'helpdesk_sla_targets', 'helpdesk_sla_holidays', 'helpdesk_triggers',
        'helpdesk_forms', 'helpdesk_form_fields', 'helpdesk_association_rules',
        'helpdesk_kb_categories', 'helpdesk_kb_articles',
        'crm_products', 'crm_contact_types', 'crm_lead_sources', 'crm_loss_reasons', 'crm_tags',
        'crm_campaigns', 'crm_sales_targets', 'crm_signature_profiles', 'crm_saved_filters',
        'crm_pipelines', 'crm_pipeline_stages', 'crm_stage_automations',
    ];

    private array $nullRefTables = ['customers', 'contracts', 'customer_contacts', 'projects', 'timesheets'];

    public function up(): void
    {
        $isHomolog = DB::table('nav_modules')->whereIn('key', ['crm', 'help_desk'])->exists();
        if (! $isHomolog) {
            return;
        }
        $erpserv = DB::table('companies')->where('slug', 'erpserv')->value('id');
        $bizify  = DB::table('companies')->where('slug', 'bizify')->value('id');
        if (! $erpserv || ! $bizify) {
            return;
        }

        // (1) Uniques globais → compostos com company_id (sempre; idempotente via IF EXISTS).
        foreach ($this->uniqueFix as [$table, $oldName, $cols]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'company_id')) {
                continue;
            }
            // Remove o unique global (pode ser constraint OU índice).
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$oldName}");
            DB::statement("DROP INDEX IF EXISTS {$oldName}");
            // Cria o composto (se ainda não existir).
            $newName = $table . '_company_' . end($cols) . '_unique';
            $colList = implode(', ', $cols);
            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$newName} ON {$table} ({$colList})");
        }

        // (2) Clone ERPSERV→BIZIFY (idempotente: pula se a BIZIFY já tem status).
        if (Schema::hasTable('helpdesk_statuses') && DB::table('helpdesk_statuses')->where('company_id', $bizify)->exists()) {
            return;
        }

        $maps = [];
        $set  = array_flip($this->order);
        $fksOf = function (string $table): array {
            $rows = DB::select(
                "SELECT kcu.column_name AS col, ccu.table_name AS ref
                 FROM information_schema.table_constraints tc
                 JOIN information_schema.key_column_usage kcu ON tc.constraint_name = kcu.constraint_name AND kcu.table_schema = tc.table_schema
                 JOIN information_schema.constraint_column_usage ccu ON tc.constraint_name = ccu.constraint_name AND ccu.table_schema = tc.table_schema
                 WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_name = ?",
                [$table]
            );
            return array_map(fn ($r) => ['col' => $r->col, 'ref' => $r->ref], $rows);
        };

        try {
        DB::transaction(function () use (&$maps, $set, $fksOf, $erpserv, $bizify) {
            foreach ($this->order as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                $hasCompany = Schema::hasColumn($table, 'company_id');
                $selfCols = [];
                $remapCols = [];
                $nullCols = [];
                $parentFilterCol = null;
                foreach ($fksOf($table) as $fk) {
                    if ($fk['ref'] === $table) {
                        $selfCols[] = $fk['col'];
                    } elseif (isset($set[$fk['ref']])) {
                        $remapCols[$fk['col']] = $fk['ref'];
                        $parentFilterCol ??= $fk['col'];
                    } elseif (in_array($fk['ref'], $this->nullRefTables, true)) {
                        $nullCols[] = $fk['col'];
                    }
                }
                if (Schema::hasColumn($table, 'parent_id') && ! in_array('parent_id', $selfCols, true) && ! isset($remapCols['parent_id'])) {
                    $selfCols[] = 'parent_id';
                }

                if ($hasCompany) {
                    $rows = DB::table($table)->where('company_id', $erpserv)->get();
                } elseif ($parentFilterCol && ! empty($maps[$remapCols[$parentFilterCol]])) {
                    $rows = DB::table($table)->whereIn($parentFilterCol, array_keys($maps[$remapCols[$parentFilterCol]]))->get();
                } else {
                    $rows = collect();
                }

                $maps[$table] = [];
                foreach ($rows as $row) {
                    $arr = (array) $row;
                    $oldId = $arr['id'] ?? null;
                    unset($arr['id']);
                    if ($hasCompany) {
                        $arr['company_id'] = $bizify;
                    }
                    foreach ($remapCols as $col => $refTable) {
                        if (! empty($arr[$col])) {
                            $arr[$col] = $maps[$refTable][$arr[$col]] ?? null;
                        }
                    }
                    foreach ($nullCols as $col) {
                        if (array_key_exists($col, $arr)) {
                            $arr[$col] = null;
                        }
                    }
                    $newId = DB::table($table)->insertGetId($arr);
                    if ($oldId !== null) {
                        $maps[$table][$oldId] = $newId;
                    }
                }

                foreach ($selfCols as $col) {
                    foreach ($maps[$table] as $newId) {
                        $oldParent = DB::table($table)->where('id', $newId)->value($col);
                        if ($oldParent !== null && isset($maps[$table][$oldParent])) {
                            DB::table($table)->where('id', $newId)->update([$col => $maps[$table][$oldParent]]);
                        } elseif ($oldParent !== null) {
                            DB::table($table)->where('id', $newId)->update([$col => null]);
                        }
                    }
                }
            }

            if (Schema::hasTable('helpdesk_team_user') && ! empty($maps['helpdesk_teams'])) {
                foreach ($maps['helpdesk_teams'] as $oldTeam => $newTeam) {
                    foreach (DB::table('helpdesk_team_user')->where('helpdesk_team_id', $oldTeam)->get() as $tu) {
                        $arr = (array) $tu;
                        unset($arr['id']);
                        $arr['helpdesk_team_id'] = $newTeam;
                        DB::table('helpdesk_team_user')->insert($arr);
                    }
                }
            }
        });
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[BIZIFY clone HD/CRM 170000] falhou (deploy não bloqueado): ' . $e->getMessage());
        }
    }

    public function down(): void
    {
    }
};
