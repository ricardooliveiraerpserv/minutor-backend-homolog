<?php

namespace App\Http\Controllers;

use App\Models\EnvEnvironment;
use App\Models\User;
use App\Prosight\SafeEnvironmentSerializer;
use App\SourceCode\SourceDocCustomerScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Prosight C3 — Ambientes (projeção READ-ONLY segura do Cofre Env* por customer_id).
 *
 * NÃO é o Cofre e NÃO concede acesso a ele: sem reveal, sem secrets, sem credenciais, sem ACL de vault.
 * Autoridade dupla: permissão `prosight.environments.view` (rota) + escopo real do cliente
 * (SourceDocCustomerScope, deny-by-default). O customer_id do request NUNCA é autoridade — é revalidado.
 * Empresa é OBRIGATÓRIA (ambiente é contexto operacional; "Todas" bloqueado). Zero-schema, read-only.
 */
class ProsightEnvironmentController extends Controller
{
    public function __construct(private SourceDocCustomerScope $scope, private SafeEnvironmentSerializer $serializer)
    {
    }

    /** GET /prosight/environments?customer_id=<id> */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Empresa OBRIGATÓRIA — a aba Ambientes exige empresa específica (nunca "Todas").
        if (! $request->filled('customer_id')) {
            return response()->json(['message' => 'Selecione uma empresa para ver os ambientes.'], 422);
        }
        $customerId = (int) $request->query('customer_id');

        // Anti-IDOR: o parâmetro é revalidado contra o escopo real do usuário (deny-by-default).
        if (! $this->scope->canAccessCustomerId($user, $customerId)) {
            return response()->json(['message' => 'Empresa fora do seu escopo.'], 403);
        }

        // Ambientes do cliente (SoftDeletes já exclui os removidos). Ordem prod→homolog→dev→dr, depois nome.
        $envs = EnvEnvironment::query()
            ->where('customer_id', $customerId)
            ->orderByRaw("array_position(ARRAY['prod','homolog','dev','dr']::text[], type)")
            ->orderBy('name')
            ->get(['id', 'customer_id', 'name', 'type', 'status', 'responsible_user_id', 'created_at', 'updated_at']);

        if ($envs->isEmpty()) {
            return response()->json(['data' => ['customer_id' => $customerId, 'environments' => []]]);
        }

        $envIds = $envs->pluck('id')->all();

        // Filhos — SOMENTE colunas allowlist (nunca *_secret_id, root_path, port, server, username, url...).
        $apps = DB::table('env_appservers')->whereIn('environment_id', $envIds)->whereNull('deleted_at')
            ->orderBy('name')->get(['environment_id', 'name', 'version', 'build', 'patch'])->groupBy('environment_id');
        $dbs = DB::table('env_databases')->whereIn('environment_id', $envIds)->whereNull('deleted_at')
            ->get(['environment_id', 'engine'])->groupBy('environment_id');
        $links = DB::table('env_links')->whereIn('environment_id', $envIds)
            ->whereIn('kind', SafeEnvironmentSerializer::ALLOWED_LINK_KINDS)
            ->get(['environment_id', 'label', 'kind'])->groupBy('environment_id');

        $responsibleNames = User::query()
            ->whereIn('id', $envs->pluck('responsible_user_id')->filter()->unique()->all())
            ->pluck('name', 'id');

        $data = $envs->map(fn ($e) => $this->serializer->serialize(
            $e,
            $apps->get($e->id) ?? collect(),
            $dbs->get($e->id) ?? collect(),
            $links->get($e->id) ?? collect(),
            $e->responsible_user_id ? ($responsibleNames[$e->responsible_user_id] ?? null) : null,
        ))->all();

        return response()->json(['data' => ['customer_id' => $customerId, 'environments' => $data]]);
    }

    /**
     * C4 — GET /prosight/environments/{environment_id}/configuration?customer_id=<id>
     * Detalhe cadastral de UM ambiente. Empresa E ambiente obrigatórios. Anti-IDOR por environment_id:
     * ambiente inexistente, de outro cliente OU fora do escopo → 404 (não revela existência).
     */
    public function configuration(Request $request, int $environmentId): JsonResponse
    {
        $user = $request->user();

        if (! $request->filled('customer_id')) {
            return response()->json(['message' => 'Selecione uma empresa e um ambiente.'], 422);
        }
        $customerId = (int) $request->query('customer_id');

        $env = EnvEnvironment::query()->whereKey($environmentId)
            ->first(['id', 'customer_id', 'name', 'type', 'status', 'responsible_user_id', 'updated_at']);

        // 404 sem revelar: não existe, ou não pertence ao cliente selecionado, ou fora do escopo do usuário.
        if (! $env || (int) $env->customer_id !== $customerId || ! $this->scope->canAccessCustomerId($user, (int) $env->customer_id)) {
            return response()->json(['message' => 'Ambiente não encontrado.'], 404);
        }

        // Filhos — SOMENTE colunas allowlist. always_on é config cadastrada (não estado live).
        $apps = DB::table('env_appservers')->where('environment_id', $env->id)->whereNull('deleted_at')
            ->orderBy('name')->get(['name', 'version', 'build', 'patch']);
        $dbs = DB::table('env_databases')->where('environment_id', $env->id)->whereNull('deleted_at')
            ->get(['engine', 'always_on']);
        $links = DB::table('env_links')->where('environment_id', $env->id)
            ->whereIn('kind', SafeEnvironmentSerializer::ALLOWED_LINK_KINDS)->get(['label', 'kind']);
        $responsibleName = $env->responsible_user_id ? User::whereKey($env->responsible_user_id)->value('name') : null;

        return response()->json(['data' => $this->serializer->serializeConfig($env, $apps, $dbs, $links, $responsibleName)]);
    }
}
