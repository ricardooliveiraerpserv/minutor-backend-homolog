<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\TimesheetTransferRule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill da ORIGEM dos apontamentos já migrados pela Transferência de Apontamentos.
 *
 * A migração sobrescreve customer_id (vira o do projeto destino) e os auto-migrados (save hook) não
 * deixavam rastro, então a empresa de origem se perdeu. Reconstruímos em 2 passadas:
 *
 *  PASSADA 1 (PRECISA — via Movidesk/HD): o apontamento guarda o nº do ticket (timesheets.ticket).
 *  O ticket do Movidesk (movidesk_tickets.ticket_id, fallback helpdesk_tickets.ticket_number) tem o
 *  CLIENTE real. Se existir uma regra no destino cuja source_customer = cliente do ticket, atribui
 *  exatamente — funciona MESMO com 2+ regras no mesmo destino (não é heurística).
 *
 *  PASSADA 2 (HEURÍSTICA — só destinos de 1 regra): remanescentes sem ticket/sem match, dentro da
 *  janela de data da única regra do destino. ⚠️ pode marcar um apontamento NATIVO do destino como
 *  migrado (risco aceito). Em destinos com 2+ regras não roda (ambíguo) → ficam "não registrados".
 *
 * Dry-run por padrão; --execute grava (update raw, sem disparar hooks/recálculo).
 */
class BackfillTransferOrigin extends Command
{
    protected $signature = 'transfer:backfill-origin {--execute : Grava de fato (sem isto é só dry-run)}';

    protected $description = 'Preenche a origem (regra/empresa) dos apontamentos já migrados: Movidesk/HD (preciso) + heurística de data (destinos de 1 regra).';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $this->info($execute ? '>> MODO EXECUÇÃO (vai gravar)' : '>> DRY-RUN (nada é gravado) — use --execute para aplicar');

        $rules = TimesheetTransferRule::get(['id', 'source_customer_id', 'target_project_id']);
        if ($rules->isEmpty()) {
            $this->warn('Nenhuma regra cadastrada.');
            return self::SUCCESS;
        }
        $ruleByTargetSource = []; // [targetProjectId][sourceCustomerId] => ruleId
        $rulesPerTarget     = []; // [targetProjectId] => nº de regras
        foreach ($rules as $r) {
            $t = (int) $r->target_project_id;
            $s = (int) $r->source_customer_id;
            $ruleByTargetSource[$t][$s] = (int) $r->id;
            $rulesPerTarget[$t] = ($rulesPerTarget[$t] ?? 0) + 1;
        }
        $targetIds = array_keys($rulesPerTarget);

        // ── PASSADA 1: Movidesk/HD (precisa) ───────────────────────────────
        $this->line('');
        $this->info('PASSADA 1 — Movidesk/HD (ticket → cliente real):');
        $rows = DB::table('timesheets')->whereNull('deleted_at')->whereNull('transfer_rule_id')
            ->whereNotNull('ticket')->where('ticket', '!=', '')
            ->where(function ($w) use ($targetIds) {
                $w->whereIn('project_id', $targetIds)->orWhereIn('real_project_id', $targetIds);
            })
            ->get(['id', 'ticket', 'project_id', 'real_project_id']);

        $tickets = $rows->pluck('ticket')->filter()->unique()->values()->all();
        $md = empty($tickets) ? [] : DB::table('movidesk_tickets')->whereIn('ticket_id', $tickets)->pluck('customer_id', 'ticket_id')->all();
        $hd = empty($tickets) ? [] : DB::table('helpdesk_tickets')->whereIn('ticket_number', $tickets)->orderByDesc('origin_company_id')->pluck('customer_id', 'ticket_number')->all();

        $p1 = 0;
        $p1ByCompany = [];
        $noTicketMatch = 0;
        foreach ($rows as $row) {
            $cust = $md[$row->ticket] ?? $hd[$row->ticket] ?? null;
            if (! $cust) { $noTicketMatch++; continue; }
            $cust = (int) $cust;
            $target = isset($ruleByTargetSource[(int) $row->project_id]) ? (int) $row->project_id : (int) $row->real_project_id;
            $ruleId = $ruleByTargetSource[$target][$cust] ?? null;
            if (! $ruleId) { $noTicketMatch++; continue; } // cliente do ticket não casa com regra desse destino
            if ($execute) {
                DB::table('timesheets')->where('id', $row->id)->update([
                    'transfer_rule_id'            => $ruleId,
                    'transfer_source_customer_id' => $cust,
                ]);
                \App\Models\TimesheetOriginLog::firstOrCreate(
                    ['timesheet_id' => $row->id, 'method' => 'ticket'],
                    ['source_customer_id' => $cust, 'rule_id' => $ruleId, 'ticket' => $row->ticket]
                );
            }
            $p1++;
            $p1ByCompany[$cust] = ($p1ByCompany[$cust] ?? 0) + 1;
        }
        foreach ($p1ByCompany as $cid => $n) {
            $this->line(sprintf('  %s (%d): %d apontamento(s)', optional(Customer::find($cid))->name ?? '#' . $cid, $cid, $n));
        }
        $this->line(sprintf('  → %d atribuído(s) por ticket; %d sem ticket casável.', $p1, $noTicketMatch));

        // ── PASSADA 2: heurística de data (só destinos de 1 regra) ─────────
        $this->line('');
        $this->info('PASSADA 2 — heurística de data (destinos com 1 única regra):');
        $byTarget = $rules->groupBy('target_project_id');
        $p2 = 0;
        $ambiguous = [];
        foreach ($byTarget as $targetId => $tRules) {
            $targetId = (int) $targetId;
            if (($rulesPerTarget[$targetId] ?? 0) > 1) { $ambiguous[$targetId] = $rulesPerTarget[$targetId]; continue; }
            $rule = TimesheetTransferRule::find($tRules->first()->id); // casts de data
            $dateCol = $rule->date_field === 'digitacao' ? 'created_at' : 'date';
            $q = DB::table('timesheets')->whereNull('deleted_at')->whereNull('transfer_rule_id')
                ->where(function ($w) use ($targetId) {
                    $w->where('project_id', $targetId)->orWhere('real_project_id', $targetId);
                });
            if ($rule->date_from)  { $q->whereDate($dateCol, '>=', $rule->date_from->toDateString()); }
            if ($rule->date_until) { $q->whereDate($dateCol, '<=', $rule->date_until->toDateString()); }
            $count = (clone $q)->count();
            if ($count > 0) {
                $srcName = optional(Customer::find($rule->source_customer_id))->name ?? ('#' . $rule->source_customer_id);
                $this->line(sprintf('  destino %d (regra %d, %s): %d apontamento(s)', $targetId, $rule->id, $srcName, $count));
                if ($execute) {
                    $affectedIds = (clone $q)->pluck('id')->all();
                    $q->update(['transfer_rule_id' => $rule->id, 'transfer_source_customer_id' => $rule->source_customer_id]);
                    foreach ($affectedIds as $tid) {
                        \App\Models\TimesheetOriginLog::firstOrCreate(
                            ['timesheet_id' => $tid, 'method' => 'heuristic'],
                            ['source_customer_id' => $rule->source_customer_id, 'rule_id' => $rule->id]
                        );
                    }
                }
            }
            $p2 += $count;
        }

        // ── PASSADA 3: garante LOG para apontamentos JÁ atribuídos sem log (ex.: os migrados antes
        //    de existir a tabela de log). Não altera a atribuição, só registra no log de origem.
        $this->line('');
        $this->info('PASSADA 3 — log para já-atribuídos sem registro:');
        $p3 = 0;
        $already = DB::table('timesheets')->whereNull('deleted_at')
            ->whereNotNull('transfer_source_customer_id')
            ->where(function ($w) use ($targetIds) {
                $w->whereIn('project_id', $targetIds)->orWhereIn('real_project_id', $targetIds);
            })
            ->whereNotExists(function ($x) {
                $x->select(DB::raw(1))->from('timesheet_origin_logs')
                    ->whereColumn('timesheet_origin_logs.timesheet_id', 'timesheets.id');
            })
            ->get(['id', 'ticket', 'transfer_source_customer_id', 'transfer_source_project_id', 'transfer_rule_id']);
        foreach ($already as $row) {
            if ($execute) {
                \App\Models\TimesheetOriginLog::create([
                    'timesheet_id'       => $row->id,
                    'source_customer_id' => $row->transfer_source_customer_id,
                    'source_project_id'  => $row->transfer_source_project_id,
                    'rule_id'            => $row->transfer_rule_id,
                    'ticket'             => $row->ticket ?: null,
                    'method'             => $row->ticket ? 'ticket' : 'backfill',
                ]);
            }
            $p3++;
        }
        $this->line(sprintf('  → %d log(s) de já-atribuídos %s.', $p3, $execute ? 'criados' : '(dry-run)'));
        if ($ambiguous) {
            $this->warn('  Destinos AMBÍGUOS (2+ regras) — não usam heurística; remanescentes sem ticket ficam "não registrados":');
            foreach ($ambiguous as $t => $n) {
                $this->line(sprintf('    projeto %d: %d regras', $t, $n));
            }
        }

        $this->line('');
        $this->info(sprintf('%s %d por ticket + %d por heurística = %d apontamento(s) %s.',
            $execute ? 'Gravado:' : 'Seriam atualizados:', $p1, $p2, $p1 + $p2, $execute ? '' : '(dry-run)'));
        return self::SUCCESS;
    }
}
