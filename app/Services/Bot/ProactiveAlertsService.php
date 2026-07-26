<?php

namespace App\Services\Bot;

use App\Enums\FeedEventType;
use App\Enums\FeedSeverity;
use App\Enums\FeedSource;
use App\Models\BotProactiveDetector;
use App\Models\Expense;
use App\Models\MovideskTicket;
use App\Models\OperationalFeed;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Executa os detectores proativos persistidos em bot_proactive_detectors.
 *
 * Cada detector é uma linha do banco com `detector_type` + `config` (jsonb).
 * O service despacha pelo tipo, faz dedupe via metadata->>'dedupe_key' em
 * janela horária configurável e grava alertas no OperationalFeed.
 *
 * Tipos suportados:
 *   - bank_hours_threshold   config: { threshold_hours }
 *   - expense_payment_age    config: { days }
 *   - timesheet_pending_age  config: { days }
 *   - ticket_stale_age       config: { days }
 *   - late_timesheets        config: { }
 *   - sql                    config: { sql, title_template, message_template }
 *   - custom                 (mesma estrutura do sql, sem execução)
 */
class ProactiveAlertsService
{
    /** Tokens proibidos no SQL custom (case-insensitive, palavra inteira). */
    private const SQL_BLOCKLIST = [
        'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'CREATE',
        'TRUNCATE', 'GRANT', 'REVOKE', 'COPY', 'CALL', 'DO',
        'COMMIT', 'ROLLBACK', 'VACUUM', 'REINDEX',
    ];

    /** Executa um detector e retorna [alerts_created, error|null]. */
    public function runDetector(BotProactiveDetector $d, bool $dry = false): array
    {
        try {
            $count = match ($d->detector_type) {
                'bank_hours_threshold'   => $this->runBankHours($d, $dry),
                'expense_payment_age'    => $this->runOldPendingExpenses($d, $dry),
                'timesheet_pending_age'  => $this->runOldPendingTimesheets($d, $dry),
                'ticket_stale_age'       => $this->runStaleTickets($d, $dry),
                'late_timesheets'        => $this->runLateTimesheets($d, $dry),
                'sql'                    => $this->runSqlDetector($d, $dry),
                'custom'                 => 0,
                default                  => 0,
            };

            if (! $dry) {
                $d->update([
                    'last_run_at'     => now(),
                    'last_run_alerts' => $count,
                    'last_run_error'  => null,
                ]);
            }
            return [$count, null];
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            Log::error("[ProactiveAlerts] detector {$d->slug}: {$msg}");
            if (! $dry) {
                $d->update([
                    'last_run_at'     => now(),
                    'last_run_alerts' => 0,
                    'last_run_error'  => substr($msg, 0, 500),
                ]);
            }
            return [0, $msg];
        }
    }

    public function runAll(bool $dry = false): int
    {
        $total = 0;
        foreach (BotProactiveDetector::query()->active()->orderBy('id')->get() as $d) {
            [$c] = $this->runDetector($d, $dry);
            $total += $c;
        }
        return $total;
    }

    /** Valida SQL custom: precisa começar com SELECT, sem múltiplos ; nem tokens proibidos. */
    public function validateSql(string $sql): ?string
    {
        $trimmed = trim($sql);
        if ($trimmed === '') return 'SQL vazio';

        // Remove ; final opcional
        $clean = rtrim($trimmed, "; \t\n\r");

        // Múltiplos statements
        if (str_contains($clean, ';')) {
            return 'SQL não pode conter múltiplas instruções (;)';
        }

        if (! preg_match('/^\s*(SELECT|WITH)\b/i', $clean)) {
            return 'SQL deve começar com SELECT (ou WITH).';
        }

        foreach (self::SQL_BLOCKLIST as $bad) {
            if (preg_match('/\b' . preg_quote($bad, '/') . '\b/i', $clean)) {
                return "Palavra reservada não permitida no SQL: {$bad}";
            }
        }
        return null;
    }

    private function recentlyAlerted(string $eventType, string $dedupeKey, int $windowHours): bool
    {
        return OperationalFeed::query()
            ->where('event_type', $eventType)
            ->where('created_at', '>=', now()->subHours(max(1, $windowHours)))
            ->whereRaw("metadata->>'dedupe_key' = ?", [$dedupeKey])
            ->exists();
    }

    private function createAlert(BotProactiveDetector $d, array $payload, bool $dry): bool
    {
        if ($dry) return true;
        OperationalFeed::create([
            'source'      => $payload['source']     ?? $d->source,
            'event_type'  => $payload['event_type'] ?? $d->event_type,
            'severity'    => $payload['severity']   ?? $d->severity,
            'title'       => $payload['title'],
            'message'     => $payload['message']    ?? null,
            'customer_id' => $payload['customer_id'] ?? null,
            'contract_id' => $payload['contract_id'] ?? null,
            'project_id'  => $payload['project_id']  ?? null,
            'metadata'    => array_merge(['detector_slug' => $d->slug], $payload['metadata'] ?? []),
        ]);
        return true;
    }

    // ─── Detectores ─────────────────────────────────────────────────────

    private function runBankHours(BotProactiveDetector $d, bool $dry): int
    {
        $threshold = (int) ($d->config['threshold_hours'] ?? 16);
        $start = now()->startOfMonth();
        $end   = now()->endOfMonth();
        $count = 0;

        $consultants = User::query()
            ->whereIn('consultant_type', ['bh_fixo', 'bh_mensal'])
            ->where('enabled', true)
            ->whereNotNull('daily_hours')
            ->get(['id', 'name', 'daily_hours', 'guaranteed_hours']);

        foreach ($consultants as $c) {
            $contractHours = (float) ($c->guaranteed_hours ?? ($c->daily_hours ? (float) $c->daily_hours * 22 : 0));
            $worked = (float) Timesheet::query()
                ->where('user_id', $c->id)
                ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                ->whereIn('status', ['approved', 'pending'])
                ->sum(DB::raw('effort_minutes::numeric / 60'));
            $balance = round($worked - $contractHours, 2);
            if (abs($balance) < $threshold) continue;

            $dedupe = "{$d->slug}:user_{$c->id}:" . $start->format('Y-m');
            if ($this->recentlyAlerted($d->event_type, $dedupe, $d->dedupe_window_hours)) continue;

            $severity = abs($balance) >= ($threshold * 2) ? FeedSeverity::Critical->value : $d->severity;
            $direction = $balance > 0 ? 'positivo' : 'negativo';

            $this->createAlert($d, [
                'severity' => $severity,
                'title'    => "Banco de horas {$direction} crítico — {$c->name}",
                'message'  => sprintf("Saldo %+.2f h (trabalhadas %.2f vs contratuais %.2f). Limite ±%dh.",
                    $balance, $worked, $contractHours, $threshold),
                'metadata' => [
                    'dedupe_key'    => $dedupe,
                    'user_id'       => $c->id,
                    'user_name'     => $c->name,
                    'balance_hours' => $balance,
                    'threshold'     => $threshold,
                ],
            ], $dry);
            $count++;
        }
        return $count;
    }

    private function runOldPendingExpenses(BotProactiveDetector $d, bool $dry): int
    {
        if (! Schema::hasColumn('expenses', 'reviewed_at') || ! Schema::hasColumn('expenses', 'paid_at')) {
            return 0;
        }
        $days = (int) ($d->config['days'] ?? 7);

        $items = Expense::query()
            ->where('status', 'approved')
            ->whereNull('paid_at')
            ->where('reviewed_at', '<=', now()->subDays($days))
            ->with(['user:id,name', 'project.customer:id,name'])
            ->limit(50)
            ->get();
        if ($items->isEmpty()) return 0;

        $dedupe = "{$d->slug}:" . now()->format('Y-m-d');
        if ($this->recentlyAlerted($d->event_type, $dedupe, $d->dedupe_window_hours)) return 0;

        $total = (float) $items->sum('amount');
        $this->createAlert($d, [
            'title'   => "Despesas aprovadas sem pagamento há > {$days} dias ({$items->count()})",
            'message' => sprintf("Total acumulado: R$ %.2f", $total),
            'metadata' => [
                'dedupe_key' => $dedupe,
                'count'      => $items->count(),
                'total'      => round($total, 2),
                'sample_ids' => $items->pluck('id')->all(),
            ],
        ], $dry);
        return 1;
    }

    private function runOldPendingTimesheets(BotProactiveDetector $d, bool $dry): int
    {
        $days = (int) ($d->config['days'] ?? 5);

        $count = Timesheet::query()
            ->where('status', 'pending')
            ->where('created_at', '<=', now()->subDays($days))
            ->count();
        if ($count === 0) return 0;

        $dedupe = "{$d->slug}:" . now()->format('Y-m-d');
        if ($this->recentlyAlerted($d->event_type, $dedupe, $d->dedupe_window_hours)) return 0;

        $this->createAlert($d, [
            'title'    => "Apontamentos pendentes há > {$days} dias ({$count})",
            'message'  => "Apontamentos aguardando aprovação há mais de {$days} dias.",
            'metadata' => ['dedupe_key' => $dedupe, 'count' => $count],
        ], $dry);
        return 1;
    }

    private function runStaleTickets(BotProactiveDetector $d, bool $dry): int
    {
        $days = (int) ($d->config['days'] ?? 3);
        $threshold = now()->subDays($days);

        $tickets = MovideskTicket::query()
            ->whereNotIn('base_status', ['Resolvido', 'Fechado', 'Cancelado'])
            ->where('updated_at', '<=', $threshold)
            ->limit(50)
            ->get(['ticket_id', 'titulo', 'status', 'customer_id', 'user_id', 'updated_at']);

        $count = 0;
        foreach ($tickets as $t) {
            $dedupe = "{$d->slug}:{$t->ticket_id}";
            if ($this->recentlyAlerted($d->event_type, $dedupe, $d->dedupe_window_hours)) continue;

            $this->createAlert($d, [
                'title'       => "Ticket #{$t->ticket_id} parado há > {$days} dias",
                'message'     => "{$t->titulo} (status: {$t->status})",
                'customer_id' => $t->customer_id,
                'metadata'    => [
                    'dedupe_key'    => $dedupe,
                    'ticket_id'     => $t->ticket_id,
                    'last_update'   => $t->updated_at?->format('Y-m-d H:i'),
                    'days_inactive' => $t->updated_at ? (int) now()->diffInDays($t->updated_at) : null,
                ],
            ], $dry);
            $count++;
        }
        return $count;
    }

    private function runLateTimesheets(BotProactiveDetector $d, bool $dry): int
    {
        $count = Timesheet::query()->where('status', 'late')->count();
        if ($count === 0) return 0;

        $dedupe = "{$d->slug}:" . now()->format('Y-m-d');
        if ($this->recentlyAlerted($d->event_type, $dedupe, $d->dedupe_window_hours)) return 0;

        $this->createAlert($d, [
            'title'    => "Apontamentos atrasados ({$count})",
            'message'  => "Apontamentos com status 'late' precisam revisão.",
            'metadata' => ['dedupe_key' => $dedupe, 'count' => $count],
        ], $dry);
        return 1;
    }

    /**
     * Detector SQL custom. Roda a query e cria 1 alerta por linha retornada,
     * substituindo placeholders {{coluna}} no title_template/message_template.
     * A linha pode incluir colunas opcionais: customer_id, contract_id,
     * project_id, dedupe_key, severity. Se dedupe_key não vier, usa hash da linha.
     */
    private function runSqlDetector(BotProactiveDetector $d, bool $dry): int
    {
        $sql = (string) ($d->config['sql'] ?? '');
        $err = $this->validateSql($sql);
        if ($err) {
            throw new \RuntimeException("SQL inválido: {$err}");
        }
        $titleTpl   = (string) ($d->config['title_template']   ?? 'Alerta: {{slug}}');
        $messageTpl = (string) ($d->config['message_template'] ?? '');
        $maxRows    = (int)    ($d->config['max_rows'] ?? 50);

        // A3 (segurança): limita o tempo por consulta — um SELECT pesado/abusivo não trava o
        // banco. SET LOCAL é escopado à transação e reseta sozinho no commit.
        $rows = DB::transaction(function () use ($sql) {
            DB::statement("SET LOCAL statement_timeout = '5s'");
            return DB::select(rtrim(trim($sql), ';'));
        });
        $rows = array_slice($rows, 0, $maxRows);

        $count = 0;
        foreach ($rows as $row) {
            $assoc = (array) $row;
            $dedupe = $assoc['dedupe_key'] ?? ($d->slug . ':' . md5(json_encode($assoc)));
            if ($this->recentlyAlerted($d->event_type, $dedupe, $d->dedupe_window_hours)) continue;

            $title = $this->renderTemplate($titleTpl, $assoc, $d);
            $msg   = $this->renderTemplate($messageTpl, $assoc, $d);

            $this->createAlert($d, [
                'severity'    => $assoc['severity'] ?? null,
                'title'       => $title,
                'message'     => $msg !== '' ? $msg : null,
                'customer_id' => $assoc['customer_id'] ?? null,
                'contract_id' => $assoc['contract_id'] ?? null,
                'project_id'  => $assoc['project_id']  ?? null,
                'metadata'    => array_merge(['dedupe_key' => $dedupe], $assoc),
            ], $dry);
            $count++;
        }
        return $count;
    }

    private function renderTemplate(string $tpl, array $row, BotProactiveDetector $d): string
    {
        if ($tpl === '') return '';
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function ($m) use ($row, $d) {
            $key = $m[1];
            if ($key === 'slug') return $d->slug;
            if ($key === 'name') return $d->name;
            $v = $row[$key] ?? '';
            return is_scalar($v) ? (string) $v : json_encode($v);
        }, $tpl);
    }
}
