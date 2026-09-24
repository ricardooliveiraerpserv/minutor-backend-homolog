<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HOMOLOG: cadastros de HD/CRM são SEPARADOS por empresa. Esta migration dá à
 * BIZIFY um ponto de partida = CÓPIA dos cadastros da ERPSERV (status, serviços,
 * categorias, SLA, gatilhos, formulários, equipes, KB; e no CRM pipelines/etapas,
 * produtos, tipos, origens, motivos, tags, campanhas, metas, filtros, automações).
 * Os DADOS transacionais (chamados, oportunidades, propostas, tarefas) NÃO são
 * copiados — a BIZIFY começa sem trabalho, segregada.
 *
 * Motor genérico: duplica ERPSERV→BIZIFY em ordem de dependência; descobre FKs em
 * runtime e remapeia (self-ref em 2 passes; ref a `users` mantém; ref a
 * cliente/contrato/projeto vira null; ref a um cadastro duplicado remapeia pro
 * novo id). Gated ao homolog; idempotente (só roda se a BIZIFY ainda não tem status).
 */
return new class extends Migration
{
    /** Ordem de dependência (pais antes de filhos). */
    private array $order = [
        // Help Desk
        'helpdesk_statuses', 'helpdesk_tags', 'helpdesk_access_profiles', 'helpdesk_comm_template',
        'helpdesk_ticket_justifications', 'helpdesk_categories', 'helpdesk_services', 'helpdesk_teams',
        'helpdesk_sla_policies', 'helpdesk_sla_targets', 'helpdesk_sla_holidays', 'helpdesk_triggers',
        'helpdesk_forms', 'helpdesk_form_fields', 'helpdesk_association_rules',
        'helpdesk_kb_categories', 'helpdesk_kb_articles',
        // CRM
        'crm_products', 'crm_contact_types', 'crm_lead_sources', 'crm_loss_reasons', 'crm_tags',
        'crm_campaigns', 'crm_sales_targets', 'crm_signature_profiles', 'crm_saved_filters',
        'crm_pipelines', 'crm_pipeline_stages', 'crm_stage_automations',
    ];

    /** Refs a estas tabelas viram NULL na cópia (dados específicos da empresa que a BIZIFY não tem). */
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
        // Idempotência: se a BIZIFY já tem status de HD, não re-clona.
        if (Schema::hasTable('helpdesk_statuses') && DB::table('helpdesk_statuses')->where('company_id', $bizify)->exists()) {
            return;
        }

        $maps = [];   // tabela => [old_id => new_id]
        $set  = array_flip($this->order);

        // FKs de uma tabela (runtime): [ ['col'=>..., 'ref'=>...], ... ]
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
                $fks = $fksOf($table);

                // Categoriza as FKs.
                $selfCols = [];   // self-ref → 2º passe
                $remapCols = [];  // col => tabela-alvo (dup-set)
                $nullCols = [];   // col → null
                $parentFilterCol = null; // p/ tabelas SEM company_id: filtra por FK ao pai já duplicado
                foreach ($fks as $fk) {
                    if ($fk['ref'] === $table) {
                        $selfCols[] = $fk['col'];
                    } elseif (isset($set[$fk['ref']])) {
                        $remapCols[$fk['col']] = $fk['ref'];
                        $parentFilterCol ??= $fk['col'];
                    } elseif (in_array($fk['ref'], $this->nullRefTables, true)) {
                        $nullCols[] = $fk['col'];
                    }
                    // ref a 'users' (ou outras) → mantém o valor original (compartilhado).
                }
                // Self-ref SEM constraint FK (ex.: helpdesk_services.parent_id): trata pelo nome.
                if (Schema::hasColumn($table, 'parent_id')
                    && ! in_array('parent_id', $selfCols, true)
                    && ! isset($remapCols['parent_id'])) {
                    $selfCols[] = 'parent_id';
                }

                // Linhas-base a duplicar.
                if ($hasCompany) {
                    $rows = DB::table($table)->where('company_id', $erpserv)->get();
                } elseif ($parentFilterCol && isset($maps[$remapCols[$parentFilterCol]])) {
                    $parentIds = array_keys($maps[$remapCols[$parentFilterCol]]);
                    $rows = $parentIds ? DB::table($table)->whereIn($parentFilterCol, $parentIds)->get() : collect();
                } else {
                    $rows = collect(); // sem company_id e sem pai duplicado → não dá pra escopar; pula
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
                            $arr[$col] = $maps[$refTable][$arr[$col]] ?? null; // remapeia; null se o pai não veio
                        }
                    }
                    foreach ($nullCols as $col) {
                        if (array_key_exists($col, $arr)) {
                            $arr[$col] = null;
                        }
                    }
                    // self-ref: mantém o valor antigo agora; corrige no 2º passe.
                    $newId = DB::table($table)->insertGetId($arr);
                    if ($oldId !== null) {
                        $maps[$table][$oldId] = $newId;
                    }
                }

                // 2º passe: remapeia self-refs pros novos ids.
                foreach ($selfCols as $col) {
                    foreach ($maps[$table] as $oldId => $newId) {
                        $oldParent = DB::table($table)->where('id', $newId)->value($col);
                        if ($oldParent !== null && isset($maps[$table][$oldParent])) {
                            DB::table($table)->where('id', $newId)->update([$col => $maps[$table][$oldParent]]);
                        } elseif ($oldParent !== null) {
                            DB::table($table)->where('id', $newId)->update([$col => null]); // pai não duplicado
                        }
                    }
                }
            }

            // Junção helpdesk_team_user: replica os membros das equipes NOVAS (mesmos usuários).
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
            \Illuminate\Support\Facades\Log::warning('[BIZIFY clone HD/CRM] falhou (deploy não bloqueado): ' . $e->getMessage());
        }
    }

    public function down(): void
    {
        // Sem rollback automático (cadastros clonados para a BIZIFY).
    }
};
