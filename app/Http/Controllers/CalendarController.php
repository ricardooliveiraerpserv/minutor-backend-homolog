<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Agenda da tela inicial: eventos do mês atual (aniversários, vencimento de contratos,
 * reajustes e eventos internos). Visão temporal — NÃO é um calendário em grade.
 */
class CalendarController extends Controller
{
    /** Eventos do mês atual, ordenados por data. */
    public function events(Request $request): JsonResponse
    {
        $u = $request->user();
        abort_unless($u, 401);

        $now = now();
        // Mês de referência (?month=YYYY-MM) — aniversários/reajustes são recorrentes, valem p/ qualquer mês.
        $ref = $request->filled('month')
            ? Carbon::createFromFormat('Y-m', (string) $request->query('month'))->startOfMonth()
            : $now->copy()->startOfMonth();
        $month  = (int) $ref->month;
        $year   = (int) $ref->year;
        // Contrato (vencimento/reajuste) carrega valor — restrito a quem enxerga contratos.
        $canSeeContracts = in_array($u->type, ['admin', 'coordenador', 'administrativo'], true);

        $eventos = collect();

        // 1) 🎂 Aniversários (mês atual) — todos os internos
        User::query()
            ->where('enabled', true)->where('type', '!=', 'cliente')->whereNotNull('birth_date')
            ->whereRaw('extract(month from birth_date) = ?', [$month])
            ->orderByRaw('extract(day from birth_date)')
            ->get(['id', 'name', 'birth_date'])
            ->each(function (User $b) use (&$eventos, $year, $month) {
                $day = (int) $b->birth_date->day;
                $eventos->push(['tipo' => 'birthday', 'data' => self::ymd($year, $month, $day), 'titulo' => $b->name]);
            });

        // 1.5) 🏖 Feriados (cadastro de feriados) — neste mês/ano, ativos. Visível p/ todos.
        \App\Models\Holiday::query()
            ->where('active', true)
            ->whereMonth('date', $month)->whereYear('date', $year)
            ->orderBy('date')
            ->get(['date', 'name'])
            ->each(function ($h) use (&$eventos) {
                $eventos->push(['tipo' => 'holiday', 'data' => $h->date->toDateString(), 'titulo' => $h->name]);
            });

        if ($canSeeContracts) {
            // 2) 📄 Vencimento de contratos (data_vencimento neste mês/ano)
            Contract::query()
                ->whereNull('deleted_at')->whereNotNull('data_vencimento')
                ->whereMonth('data_vencimento', $month)->whereYear('data_vencimento', $year)
                ->with('customer:id,name')
                ->get(['id', 'customer_id', 'project_name', 'data_vencimento'])
                ->each(function (Contract $c) use (&$eventos) {
                    $eventos->push([
                        'tipo'   => 'contract_expiration',
                        'data'   => $c->data_vencimento->toDateString(),
                        'titulo' => $c->customer?->name ?: ($c->project_name ?: 'Contrato'),
                    ]);
                });

            // 3) 💰 Reajustes (aniversário anual do último reajuste/assinatura caindo neste mês)
            Contract::query()
                ->whereNull('deleted_at')
                ->where(fn ($q) => $q->whereNotNull('data_ultimo_reajuste')->orWhereNotNull('data_assinatura'))
                ->with('customer:id,name')
                ->get(['id', 'customer_id', 'project_name', 'data_ultimo_reajuste', 'data_assinatura'])
                ->each(function (Contract $c) use (&$eventos, $year, $month) {
                    $base = $c->data_ultimo_reajuste ?: $c->data_assinatura;
                    if (!$base || (int) $base->month !== $month) return;     // só aniversário deste mês
                    $eventos->push([
                        'tipo'   => 'reajuste',
                        'data'   => self::ymd($year, $month, (int) $base->day),
                        'titulo' => $c->customer?->name ?: ($c->project_name ?: 'Contrato'),
                    ]);
                });
        }

        // (Removido: notificações NÃO são eventos de agenda — usavam expires_at como "data", gerando
        //  falsos "eventos hoje" a partir de avisos/decisões que apenas vencem hoje.)

        // 5) 📝 Tarefas com data de que o usuário é RESPONSÁVEL no mês de referência.
        \App\Models\Task::where('assigned_to', $u->id)
            ->whereNotNull('due_date')
            ->whereMonth('due_date', $month)->whereYear('due_date', $year)
            ->get(['id', 'title', 'due_date', 'due_time'])
            ->each(function ($t) use (&$eventos) {
                $eventos->push([
                    'tipo'   => 'task',
                    'data'   => $t->due_date->toDateString(),
                    'titulo' => trim((string) $t->title) . ($t->due_time ? ' · ' . substr((string) $t->due_time, 0, 5) : ''),
                ]);
            });

        // 6) 📅 Outlook — eventos sincronizados da conta Microsoft do usuário (cache). Defensivo.
        try {
            $integ = \App\Models\UserIntegration::where('user_id', $u->id)->where('provider', 'microsoft')->first();
            foreach (($integ?->cached_events ?? []) as $ev) {
                $d = (string) ($ev['data'] ?? '');
                if (strlen($d) === 10 && (int) substr($d, 5, 2) === $month && (int) substr($d, 0, 4) === $year) {
                    $eventos->push(['tipo' => 'outlook', 'data' => $d, 'titulo' => (string) ($ev['titulo'] ?? 'Compromisso')]);
                }
            }
        } catch (\Throwable) { /* nunca trava o calendário */ }

        $ordered = $eventos
            ->sortBy([['data', 'asc'], ['titulo', 'asc']])
            ->map(fn ($e) => array_merge($e, ['is_today' => $e['data'] === $now->toDateString()]))
            ->values();

        return response()->json(['data' => [
            'eventos'  => $ordered,
            'mes'      => $ref->locale('pt_BR')->isoFormat('MMMM'),
            'mes_num'  => $month,
            'ano'      => $year,
            'mes_ref'  => $ref->format('Y-m'),
        ]]);
    }

    /** Monta a data do evento no ano/mês corrente (aniversário recorrente), tolerando dia inválido. */
    private static function ymd(int $year, int $month, int $day): string
    {
        $last = Carbon::create($year, $month, 1)->endOfMonth()->day;
        return Carbon::create($year, $month, min($day, $last))->toDateString();
    }
}
