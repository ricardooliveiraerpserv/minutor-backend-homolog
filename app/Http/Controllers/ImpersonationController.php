<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AccessControl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Módulo "Ver como" (impersonation) — habilitado em produção como ferramenta de suporte.
 * Impersonation real: gera um token Sanctum do usuário-alvo. O app inteiro passa a
 * renderizar a visão REAL dele (nunca diverge, pois é o mesmo app rodando como ele).
 *
 * Autorização em 2 camadas:
 *  1. BLOCO (cliente/consultor/parceiro) liberado por perfil no Configurador → tela `/ver-como`,
 *     ação `ver_como.<bloco>` (AccessControl::allows). Admin/administrativo têm todos os blocos.
 *  2. TRAVA DE NÍVEL (hardcoded, não configurável): você só impersona privilégio ESTRITAMENTE
 *     menor que o seu. Admin/administrativo (topo) impersonam qualquer um. Evita escalar
 *     privilégio (ex.: coordenador virando admin, ou vendo outro coordenador).
 */
class ImpersonationController extends Controller
{
    /** Perfis do topo: veem/impersonam qualquer um, sem trava de nível. */
    private const TOP_TYPES = ['admin', 'administrativo'];

    /** Rank de privilégio p/ "Ver como" (maior = mais poder). */
    private static function privRank(string $type): int
    {
        return match ($type) {
            'admin', 'administrativo' => 4,
            'coordenador'             => 3,
            'consultor'               => 2,
            default                   => 1, // cliente, parceiro_admin, …
        };
    }

    /** Requester pode impersonar alguém deste `type`? (topo = sempre; demais = só nível abaixo). */
    private function canImpersonateType(User $requester, string $targetType): bool
    {
        if (in_array($requester->type, self::TOP_TYPES, true)) return true;
        return self::privRank($targetType) < self::privRank($requester->type);
    }

    /** Types que o requester NÃO pode impersonar (rank >= o dele) — p/ filtrar a busca. */
    private function blockedTargetTypes(User $requester): array
    {
        if (in_array($requester->type, self::TOP_TYPES, true)) return [];
        $rank = self::privRank($requester->type);
        $all  = ['admin', 'administrativo', 'coordenador', 'consultor', 'cliente', 'parceiro_admin'];
        return array_values(array_filter($all, fn ($t) => self::privRank($t) >= $rank));
    }

    /** Blocos liberados ao requester (topo = todos; demais = por AccessControl da tela /ver-como). */
    private function allowedKinds(User $user): array
    {
        $kinds = ['cliente', 'consultor', 'parceiro'];
        if (in_array($user->type, self::TOP_TYPES, true)) return $kinds;
        return array_values(array_filter(
            $kinds,
            fn ($k) => AccessControl::allows($user, '/ver-como', "ver_como.$k")
        ));
    }

    /** Bloqueia se o usuário não tiver o bloco `$kind` liberado. */
    private function kindGuard(User $user, string $kind): ?JsonResponse
    {
        if (!in_array($kind, $this->allowedKinds($user), true)) {
            return response()->json(['message' => 'Acesso negado a este tipo de visão.'], 403);
        }
        return null;
    }

    /** Blocos liberados ao usuário logado — o FE usa p/ mostrar só os tiles permitidos. */
    public function kinds(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->allowedKinds($request->user())]);
    }

    /**
     * Candidatos a impersonar por tipo de visão.
     * kind=cliente | consultor | parceiro ; admin=0|1 (só p/ parceiro) ; q=busca.
     */
    public function candidates(Request $request): JsonResponse
    {
        $me        = $request->user();
        $kind      = (string) $request->query('kind', 'consultor');
        if ($resp = $this->kindGuard($me, $kind)) return $resp;

        $q         = trim((string) $request->query('q', ''));
        $admin     = $request->boolean('admin');
        $filter    = (string) $request->query('filter', '');   // consultor: consultant_type OU type (bh/horista/fixo/coordenador/…)
        $partnerId = $request->query('partner_id');            // parceiro: filtrar por empresa parceira

        $query = User::query()->where('enabled', true);

        // Trava de nível: some da lista quem tem privilégio igual/maior que o do solicitante
        // (topo = sem restrição). Impede o coordenador de "ver como" admin/administrativo/coordenador.
        if ($blocked = $this->blockedTargetTypes($me)) {
            $query->whereNotIn('type', $blocked);
        }

        switch ($kind) {
            case 'cliente':
                // Usuários de cliente (login do portal).
                $query->where('type', 'cliente')->whereNotNull('customer_id')
                    ->with('customer:id,name');
                break;

            case 'parceiro':
                // Membros do parceiro são todos type=parceiro_admin; a distinção admin/não
                // é a flag is_executive (executivo/ADM do parceiro vs membro comum) — mesma
                // regra do FechamentoParceiro.
                $query->where('type', 'parceiro_admin')->whereNotNull('partner_id')
                    ->where('is_executive', $admin)
                    ->with('partner:id,name');
                if ($partnerId) {
                    $query->where('partner_id', (int) $partnerId);
                }
                break;

            case 'consultor':
            default:
                // Equipe interna: consultor, coordenador, administrativo, admin, diretor... —
                // tudo que NÃO é cliente nem vinculado a parceiro.
                $query->whereNotIn('type', ['cliente', 'parceiro_admin'])
                    ->whereNull('partner_id');
                // Filtro por vínculo (consultant_type: horista/banco_de_horas/fixo) OU por
                // cargo (type: consultor/coordenador/administrativo/admin).
                if ($filter !== '') {
                    if (in_array($filter, ['horista', 'banco_de_horas', 'fixo'], true)) {
                        $query->where('consultant_type', $filter);
                    } else {
                        $query->where('type', $filter);
                    }
                }
                break;
        }

        if ($q !== '') {
            // Busca por TOKENS (cada palavra deve aparecer em nome ou e-mail) — robusta a
            // nomes com espaços/quebras estranhas no cadastro (ex.: "Alessandra\n\n Cavaignac").
            foreach (preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY) as $term) {
                $query->where(function ($sub) use ($term) {
                    $sub->where('name', 'ilike', "%{$term}%")
                        ->orWhere('email', 'ilike', "%{$term}%");
                });
            }
        }

        $users = $query->orderBy('name')->limit(30)
            ->get(['id', 'name', 'email', 'type', 'consultant_type', 'customer_id', 'partner_id']);

        $data = $users->map(fn (User $u) => [
            'id'              => $u->id,
            'name'            => $u->name,
            'email'           => $u->email,
            'type'            => $u->type,
            'consultant_type' => $u->consultant_type,
            'vinculo'         => $u->customer?->name ?? $u->partner?->name ?? null,
        ]);

        return response()->json(['data' => $data]);
    }

    /**
     * Lista as empresas parceiras (id + nome) que têm ao menos um membro impersonável —
     * usado no filtro de empresa parceira do módulo "Ver como".
     */
    public function partners(Request $request): JsonResponse
    {
        if ($resp = $this->kindGuard($request->user(), 'parceiro')) return $resp;

        $partners = \App\Models\Partner::whereHas('users', fn ($q) => $q->where('type', 'parceiro_admin')->where('enabled', true))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name]);

        return response()->json(['data' => $partners]);
    }

    /**
     * Gera um token de impersonation para o usuário-alvo.
     */
    public function impersonate(Request $request): JsonResponse
    {
        $me   = $request->user();
        $data = $request->validate(['user_id' => 'required|integer|exists:users,id']);

        /** @var User $target */
        $target = User::findOrFail($data['user_id']);

        if ($target->id === Auth::id()) {
            return response()->json(['message' => 'Você já é este usuário.'], 422);
        }

        // Bloco do tipo do alvo tem que estar liberado ao solicitante.
        $targetKind = $target->type === 'cliente' ? 'cliente'
            : ($target->type === 'parceiro_admin' ? 'parceiro' : 'consultor');
        if ($resp = $this->kindGuard($me, $targetKind)) return $resp;

        // Trava de nível (hardcoded): nunca impersona privilégio igual/maior (topo é exceção).
        if (!$this->canImpersonateType($me, $target->type)) {
            return response()->json(['message' => 'Você não pode ver como um usuário deste nível.'], 403);
        }

        // No máximo 1 token de impersonation por usuário-alvo.
        $target->tokens()->where('name', 'impersonation')->delete();
        $token = $target->createToken('impersonation')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => [
                'id'      => $target->id,
                'name'    => $target->name,
                'type'    => $target->type,
                'vinculo' => $target->customer?->name ?? $target->partner?->name ?? null,
            ],
        ]);
    }
}
