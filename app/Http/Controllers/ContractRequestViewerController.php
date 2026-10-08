<?php

namespace App\Http\Controllers;

use App\Models\ContractRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gestão dos clientes convidados a VER uma REQUISIÇÃO no pipeline (pré-projeto).
 * Paralelo ao ProjectClientViewerController. Ao gerar o projeto, os convidados
 * são herdados para project_client_viewers.
 */
class ContractRequestViewerController extends Controller
{
    public function index(ContractRequest $contractRequest, Request $request): JsonResponse
    {
        if (($err = $this->ensureCanManage($request, $contractRequest)) !== null) return $err;

        $items = $contractRequest->clientViewers()
            ->where('users.enabled', true)
            ->select('users.id', 'users.name', 'users.email')
            ->orderBy('users.name')
            ->get();

        return response()->json(['items' => $items]);
    }

    /** Clientes ELEGÍVEIS: só os ATIVOS do MESMO customer da requisição, ainda não vinculados. */
    public function available(ContractRequest $contractRequest, Request $request): JsonResponse
    {
        if (($err = $this->ensureCanManage($request, $contractRequest)) !== null) return $err;

        if (!$contractRequest->customer_id) {
            return response()->json(['items' => []]);
        }

        $already = $contractRequest->clientViewers()->pluck('users.id')->all();

        $items = User::where('type', 'cliente')
            ->where('customer_id', $contractRequest->customer_id)
            ->where('enabled', true)
            ->whereNotIn('id', $already)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return response()->json(['items' => $items]);
    }

    public function store(ContractRequest $contractRequest, Request $request): JsonResponse
    {
        if (($err = $this->ensureCanManage($request, $contractRequest)) !== null) return $err;

        $data = $request->validate(['user_id' => 'required|integer|exists:users,id']);

        $user = User::find($data['user_id']);
        if (!$user || !$user->isCliente()) {
            return response()->json(['message' => 'Só usuários do tipo cliente podem ser convidados.'], 422);
        }
        if (!$user->enabled) {
            return response()->json(['message' => 'Este cliente está inativo e não pode ser convidado.'], 422);
        }
        if ((int) $user->customer_id !== (int) $contractRequest->customer_id) {
            return response()->json(['message' => 'Este cliente é de outra empresa e não pode ver esta requisição.'], 422);
        }

        $alreadyViewer = $contractRequest->clientViewers()->where('users.id', $user->id)->exists();

        $contractRequest->clientViewers()->syncWithoutDetaching([$user->id]);

        // Convidar implica acesso ao módulo Projetos (a requisição vive em Demandas e Projetos).
        $mods = $user->allowed_modules;
        if (is_array($mods) && !in_array('projetos', $mods, true)) {
            $user->allowed_modules = array_values(array_merge($mods, ['projetos']));
            $user->save();
        }

        if (!$alreadyViewer) {
            $this->notifyInvite($contractRequest, $user, $request->user());
        }

        return response()->json([
            'item' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
        ], 201);
    }

    public function destroy(ContractRequest $contractRequest, User $user, Request $request): JsonResponse
    {
        if (($err = $this->ensureCanManage($request, $contractRequest)) !== null) return $err;

        $contractRequest->clientViewers()->detach($user->id);

        return response()->json(['detached' => true]);
    }

    /**
     * Quem pode gerir: admin, coordenador, ou gestor do cliente (mesmo customer da requisição).
     */
    private function ensureCanManage(Request $request, ContractRequest $contractRequest): ?JsonResponse
    {
        $u = $request->user();
        $isTeam = $u && (
            (method_exists($u, 'isAdmin') && $u->isAdmin())
            || (method_exists($u, 'isCoordenador') && $u->isCoordenador())
        );
        $isClientManager = $u
            && method_exists($u, 'isCliente') && $u->isCliente()
            && $u->is_customer_manager
            && (int) $u->customer_id === (int) $contractRequest->customer_id;

        if (!$isTeam && !$isClientManager) {
            return response()->json(['message' => 'Apenas coordenador, admin ou gestor do cliente podem gerir os participantes da requisição.'], 403);
        }
        return null;
    }

    /** E-mail + notificação ao convidado: nome da requisição e link p/ Demandas e Projetos. */
    private function notifyInvite(ContractRequest $contractRequest, User $invited, ?User $inviter): void
    {
        try {
            $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');
            $url  = $base . '/contratos/pipeline?request=' . $contractRequest->id;
            $nome = $contractRequest->project_name ?: ('Requisição #' . $contractRequest->id);

            $n = \App\Models\AppNotification::create([
                'title'        => 'Você foi convidado para uma requisição',
                'message'      => e($inviter?->name ?? 'A equipe')
                    . ' convidou você para acompanhar a requisição <b>' . e($nome) . '</b>.'
                    . ' Acesse em <b>Demandas e Projetos</b> no Minutor.',
                'type'         => 'info',
                'priority'     => 'medium',
                'target_users' => [$invited->id],
                'send_email'   => true,
                'visible'      => true,
                'cta_label'    => 'Abrir Demandas e Projetos',
                'cta_url'      => $url,
                'created_by'   => $inviter?->id,
                'expires_at'   => now()->addDays(30),
            ]);

            app(\App\Http\Controllers\NotificationController::class)->emailNotification($n);
        } catch (\Throwable $e) {
            \Log::warning('convite requisição: e-mail falhou', [
                'contract_request_id' => $contractRequest->id, 'user_id' => $invited->id, 'err' => $e->getMessage(),
            ]);
        }
    }
}
