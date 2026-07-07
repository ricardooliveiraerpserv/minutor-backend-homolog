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
    /** Tipos de evento da agenda + rótulos (config de visibilidade por perfil). */
    const TYPE_LABELS = [
        'birthday'            => 'Aniversário',
        'holiday'             => 'Feriado',
        'contract_expiration' => 'Vencimento',
        'reajuste'            => 'Reajuste',
        'task'                => 'Tarefa',
        'outlook'             => 'Outlook',
    ];
    /** TODOS os perfis (users.type) entram na matriz de visibilidade. */
    const PROFILE_LABELS = [
        'admin'          => 'Administrador',
        'administrativo' => 'Administrativo',
        'coordenador'    => 'Coordenador',
        'consultor'      => 'Consultor',
        'parceiro_admin' => 'Parceiro',
        'cliente'        => 'Cliente',
    ];
    /** Padrão: administrativos (vencimento/reajuste) só admin/administrativo/coordenador; o resto, todos. */
    const DEFAULT_VISIBILITY = [
        'birthday'            => ['admin', 'administrativo', 'coordenador', 'consultor', 'parceiro_admin', 'cliente'],
        'holiday'             => ['admin', 'administrativo', 'coordenador', 'consultor', 'parceiro_admin', 'cliente'],
        'contract_expiration' => ['admin', 'administrativo', 'coordenador'],
        'reajuste'            => ['admin', 'administrativo', 'coordenador'],
        'task'                => ['admin', 'administrativo', 'coordenador', 'consultor', 'parceiro_admin', 'cliente'],
        'outlook'             => ['admin', 'administrativo', 'coordenador', 'consultor', 'parceiro_admin', 'cliente'],
    ];

    /** Config atual de visibilidade (saved ∪ defaults, saneada) — { tipo: [perfis] }. */
    private static function visibilityConfig(): array
    {
        $saved = \App\Models\SystemSetting::get('calendar_visibility', []);
        if (!is_array($saved)) $saved = [];
        $out = [];
        foreach (array_keys(self::TYPE_LABELS) as $t) {
            $out[$t] = array_values(array_intersect(
                (array) ($saved[$t] ?? self::DEFAULT_VISIBILITY[$t] ?? []),
                array_keys(self::PROFILE_LABELS)
            ));
        }
        return $out;
    }

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
        // Visibilidade por tipo × perfil — TODOS os perfis (inclusive admin) respeitam a matriz.
        // O padrão já concede tudo a admin/administrativo; o ⚙️ da agenda é admin-only no FE.
        $vis = self::visibilityConfig();
        $canSee = fn (string $tipo) => in_array($u->type, $vis[$tipo] ?? [], true);
        // Só carrega contratos se algum dos tipos de contrato for visível a este perfil (perf).
        $canSeeContracts = $canSee('contract_expiration') || $canSee('reajuste');

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
                    $eventos->push([
                        'tipo'        => 'outlook',
                        'data'        => $d,
                        'titulo'      => (string) ($ev['titulo'] ?? 'Compromisso'),
                        'hora'        => $ev['hora'] ?? null,
                        'hora_fim'    => $ev['hora_fim'] ?? null,
                        'local'       => $ev['local'] ?? '',
                        'link'        => $ev['link'] ?? '',
                        'organizador' => $ev['organizador'] ?? '',
                        'convidados'  => $ev['convidados'] ?? [],
                    ]);
                }
            }
        } catch (\Throwable) { /* nunca trava o calendário */ }

        $ordered = $eventos
            ->filter(fn ($e) => $canSee($e['tipo']))   // visibilidade por tipo × perfil
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

    /** GET — config de visibilidade da agenda (tipos × perfis) + catálogos p/ a UI. */
    public function visibility(Request $request): JsonResponse
    {
        abort_unless($request->user(), 401);
        return response()->json(['data' => [
            'visibility' => self::visibilityConfig(),
            'types'      => self::TYPE_LABELS,
            'profiles'   => self::PROFILE_LABELS,
        ]]);
    }

    /** PUT — salva a config de visibilidade (admin). Só aceita tipos/perfis conhecidos. */
    public function saveVisibility(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $v = $request->validate(['visibility' => 'required|array']);
        $clean = [];
        foreach (array_keys(self::TYPE_LABELS) as $t) {
            $clean[$t] = array_values(array_intersect(
                array_map('strval', (array) ($v['visibility'][$t] ?? [])),
                array_keys(self::PROFILE_LABELS)
            ));
        }
        \App\Models\SystemSetting::set('calendar_visibility', $clean, 'json', 'calendar', 'Visibilidade da agenda por perfil');
        return response()->json(['data' => ['visibility' => $clean]]);
    }

    /** Monta a data do evento no ano/mês corrente (aniversário recorrente), tolerando dia inválido. */
    private static function ymd(int $year, int $month, int $day): string
    {
        $last = Carbon::create($year, $month, 1)->endOfMonth()->day;
        return Carbon::create($year, $month, min($day, $last))->toDateString();
    }
}
