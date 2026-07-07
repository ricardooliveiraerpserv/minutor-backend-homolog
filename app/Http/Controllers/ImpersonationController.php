<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Módulo "Ver como" (SOMENTE fora de produção — ferramenta de simulação da Replica).
 * Impersonation real: gera um token Sanctum do usuário-alvo. O app inteiro passa a
 * renderizar a visão REAL dele (nunca diverge, pois é o mesmo app rodando como ele).
 */
class ImpersonationController extends Controller
{
    /** Exige admin. (Habilitado em produção — "Ver como" é ferramenta de suporte do admin.) */
    private function guard(): ?JsonResponse
    {
        if (!Auth::user()?->isAdmin()) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }
        return null;
    }

    /**
     * Candidatos a impersonar por tipo de visão.
     * kind=cliente | consultor | parceiro ; admin=0|1 (só p/ parceiro) ; q=busca.
     */
    public function candidates(Request $request): JsonResponse
    {
        if ($resp = $this->guard()) return $resp;

        $kind      = (string) $request->query('kind', 'consultor');
        $q         = trim((string) $request->query('q', ''));
        $admin     = $request->boolean('admin');
        $filter    = (string) $request->query('filter', '');   // consultor: consultant_type OU type (bh/horista/fixo/coordenador/…)
        $partnerId = $request->query('partner_id');            // parceiro: filtrar por empresa parceira

        $query = User::query()->where('enabled', true);

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
        if ($resp = $this->guard()) return $resp;

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
        if ($resp = $this->guard()) return $resp;

        $data = $request->validate(['user_id' => 'required|integer|exists:users,id']);

        /** @var User $target */
        $target = User::findOrFail($data['user_id']);

        if ($target->id === Auth::id()) {
            return response()->json(['message' => 'Você já é este usuário.'], 422);
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
