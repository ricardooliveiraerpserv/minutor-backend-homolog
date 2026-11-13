<?php

namespace App\Http\Controllers;

use App\Models\LoginLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Acessos de usuários e clientes (tela "Acessos"): quem já acessou, último acesso, nº de
 * logins, online agora, nunca acessou. Admin-only. Fontes: login_logs + users.last_login_at
 * + user_presence (online). "Online" = presence.last_seen_at nos últimos 5 min.
 */
class AccessLogController extends Controller
{
    private function ensureAdmin(Request $request): void
    {
        abort_unless(optional($request->user())->isAdmin(), 403, 'Apenas administradores.');
    }

    /** GET /access-logs/summary — totais no universo do filtro (perfil + busca). Os cards
     *  obedecem os filtros; o refino acessou/nunca NÃO entra (os cards mostram essa distribuição). */
    public function summary(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);
        $total   = $this->scoped($request)->count();
        $acessou = $this->scoped($request)->whereNotNull('users.last_login_at')->count();
        $online  = $this->scoped($request)
            ->join('user_presence as pr', 'pr.user_id', '=', 'users.id')
            ->where('pr.status', 'online')->where('pr.last_seen_at', '>=', now()->subMinutes(5))->count();

        // Adoção por perfil (todos os perfis; respeita a busca, não o filtro de perfil — senão 1 barra).
        $byType = User::query()
            ->when($request->filled('search'), function ($qq) use ($request) {
                $s = '%' . strtolower($request->input('search')) . '%';
                $qq->leftJoin('customers as cs', 'cs.id', '=', 'users.customer_id')
                    ->where(fn ($w) => $w->whereRaw('LOWER(users.name) LIKE ?', [$s])->orWhereRaw('LOWER(users.email) LIKE ?', [$s])->orWhereRaw('LOWER(COALESCE(cs.name, \'\')) LIKE ?', [$s]));
            })
            ->selectRaw('users.type, COUNT(*) total, COUNT(users.last_login_at) acessaram')
            ->groupBy('users.type')->orderByRaw('COUNT(*) DESC')->get()
            ->map(fn ($r) => ['type' => $r->type, 'total' => (int) $r->total, 'acessaram' => (int) $r->acessaram]);

        // Logins por dia (últimos 30 dias) — respeita perfil + busca.
        $from = now()->subDays(29)->startOfDay();
        $byDay = DB::table('login_logs as l')
            ->join('users as users', 'users.id', '=', 'l.user_id')
            ->where('l.created_at', '>=', $from)
            ->when($request->filled('type'), fn ($qq) => $qq->where('users.type', $request->input('type')))
            ->when($request->filled('search'), function ($qq) use ($request) {
                $s = '%' . strtolower($request->input('search')) . '%';
                $qq->where(fn ($w) => $w->whereRaw('LOWER(users.name) LIKE ?', [$s])->orWhereRaw('LOWER(users.email) LIKE ?', [$s]));
            })
            ->selectRaw('CAST(l.created_at AS date) as day, COUNT(*) total, COUNT(DISTINCT l.user_id) usuarios')
            ->groupByRaw('CAST(l.created_at AS date)')->orderBy('day')->get()
            ->map(fn ($r) => ['day' => (string) $r->day, 'total' => (int) $r->total, 'usuarios' => (int) $r->usuarios]);

        // Ranking: quem mais acessa (top 8 por nº de logins) — respeita perfil + busca.
        $topUsers = DB::table('login_logs as l')
            ->join('users as users', 'users.id', '=', 'l.user_id')
            ->when($request->filled('type'), fn ($qq) => $qq->where('users.type', $request->input('type')))
            ->when($request->filled('search'), function ($qq) use ($request) {
                $s = '%' . strtolower($request->input('search')) . '%';
                $qq->where(fn ($w) => $w->whereRaw('LOWER(users.name) LIKE ?', [$s])->orWhereRaw('LOWER(users.email) LIKE ?', [$s]));
            })
            ->selectRaw('users.id, users.name, users.type, COUNT(*) logins, MAX(l.created_at) last_login')
            ->groupBy('users.id', 'users.name', 'users.type')
            ->orderByRaw('COUNT(*) DESC')->limit(8)->get()
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'type' => $r->type, 'logins' => (int) $r->logins, 'last_login' => $r->last_login ? \Illuminate\Support\Carbon::parse($r->last_login)->toIso8601String() : null]);

        return response()->json(['data' => [
            'total' => $total, 'acessaram' => $acessou, 'nunca' => $total - $acessou, 'online' => $online,
            'by_type' => $byType, 'by_day' => $byDay, 'top_users' => $topUsers,
        ]]);
    }

    /** Users já com filtro de perfil + busca aplicado (sem o filtro acessou/nunca). */
    private function scoped(Request $request)
    {
        return User::query()
            ->when($request->filled('type'), fn ($qq) => $qq->where('users.type', $request->input('type')))
            ->when($request->filled('search'), function ($qq) use ($request) {
                $s = '%' . strtolower($request->input('search')) . '%';
                $qq->leftJoin('customers as cs', 'cs.id', '=', 'users.customer_id')
                    ->where(fn ($w) => $w->whereRaw('LOWER(users.name) LIKE ?', [$s])->orWhereRaw('LOWER(users.email) LIKE ?', [$s])->orWhereRaw('LOWER(COALESCE(cs.name, \'\')) LIKE ?', [$s]));
            });
    }

    /** GET /access-logs — lista de usuários com agregados de acesso. */
    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);
        $sub = DB::table('login_logs')->selectRaw('user_id, COUNT(*) logins, MIN(created_at) first_login, MAX(created_at) last_login')->groupBy('user_id');

        $q = User::query()
            ->leftJoinSub($sub, 'll', 'll.user_id', '=', 'users.id')
            ->leftJoin('user_presence as pr', 'pr.user_id', '=', 'users.id')
            ->leftJoin('customers as c', 'c.id', '=', 'users.customer_id')
            ->when($request->filled('type'), fn ($qq) => $qq->where('users.type', $request->input('type')))
            ->when($request->input('access') === 'never', fn ($qq) => $qq->whereNull('users.last_login_at'))
            ->when($request->input('access') === 'yes', fn ($qq) => $qq->whereNotNull('users.last_login_at'))
            ->when($request->boolean('online'), fn ($qq) => $qq->where('pr.status', 'online')->where('pr.last_seen_at', '>=', now()->subMinutes(5)))
            ->when($request->filled('search'), function ($qq) use ($request) {
                $s = '%' . strtolower($request->input('search')) . '%';
                $qq->where(fn ($w) => $w->whereRaw('LOWER(users.name) LIKE ?', [$s])->orWhereRaw('LOWER(users.email) LIKE ?', [$s])->orWhereRaw('LOWER(COALESCE(c.name, \'\')) LIKE ?', [$s]));
            })
            ->selectRaw("users.id, users.name, users.email, users.type, users.enabled, users.last_login_at,
                c.name as customer_name,
                COALESCE(ll.logins, 0) as logins, ll.first_login, ll.last_login,
                pr.status as presence_status, pr.last_seen_at")
            // último acesso = o mais recente entre presença e login; nunca-acessou por último.
            ->orderByRaw('GREATEST(COALESCE(pr.last_seen_at, \'epoch\'), COALESCE(users.last_login_at, \'epoch\')) DESC')
            // desempate estável (nunca-acessou empatam em 'epoch') → paginação ("Carregar mais") sem repetir/pular.
            ->orderBy('users.id');

        $page = $q->paginate(min((int) $request->input('pageSize', 40), 100));
        $now = now();

        return response()->json([
            'data' => collect($page->items())->map(function ($u) use ($now) {
                $lastSeen = $u->last_seen_at ? \Illuminate\Support\Carbon::parse($u->last_seen_at) : null;
                $lastLogin = $u->last_login ? \Illuminate\Support\Carbon::parse($u->last_login) : ($u->last_login_at ? \Illuminate\Support\Carbon::parse($u->last_login_at) : null);
                // "Último acesso" exibido = atividade mais recente (presença) ou, na falta, o login.
                $lastAccess = $lastSeen && (!$lastLogin || $lastSeen->gt($lastLogin)) ? $lastSeen : $lastLogin;
                $online = $u->presence_status === 'online' && $lastSeen && $lastSeen->gt($now->copy()->subMinutes(5));
                return [
                    'id'            => $u->id,
                    'name'          => $u->name,
                    'email'         => $u->email,
                    'type'          => $u->type,
                    'enabled'       => (bool) $u->enabled,
                    'customer'      => $u->customer_name,
                    'ja_acessou'    => $u->last_login_at !== null || $u->logins > 0,
                    'logins'        => (int) $u->logins,
                    'first_login'   => $u->first_login ? \Illuminate\Support\Carbon::parse($u->first_login)->toIso8601String() : null,
                    'last_access'   => $lastAccess ? $lastAccess->toIso8601String() : null,
                    'online'        => $online,
                ];
            }),
            'hasNext' => $page->hasMorePages(),
            'total'   => $page->total(),
        ]);
    }

    /** GET /access-logs/{user}/history — histórico de logins de um usuário. */
    public function history(Request $request, User $user): JsonResponse
    {
        $this->ensureAdmin($request);
        $rows = LoginLog::where('user_id', $user->id)->orderByDesc('created_at')->limit(100)->get()
            ->map(fn (LoginLog $l) => [
                'id'         => $l->id,
                'at'         => optional($l->created_at)->toIso8601String(),
                'ip'         => $l->ip,
                'device'     => $l->device,
                'user_agent' => $l->user_agent,
            ]);
        return response()->json(['data' => $rows]);
    }
}
