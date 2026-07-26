<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\EnvAccessLog;
use App\Models\EnvClientVault;
use App\Models\EnvEnvironment;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Cofre de Ambientes — clientes-vault e ambientes. Camada ADITIVA: reusa `vaults`
 * (type='client') + `vault_members` (distribuição de chave por RSA) sem tocar em
 * nada da segurança existente. Metadados em CLARO; segredos vivem em env_secrets.
 */
class EnvironmentController extends Controller
{
    private const INTERNAL_TYPES = ['admin', 'administrativo', 'coordenador', 'consultor'];

    private function guardInternal(Request $request): User
    {
        $user = $request->user();
        abort_unless(in_array($user->effectiveType(), self::INTERNAL_TYPES, true), 403);

        return $user;
    }

    /** Membership do usuário no cliente-vault (reusa VaultMember). 404 se não é membro. */
    private function membershipByVault(Request $request, int $vaultId, ?string $needRole = null): VaultMember
    {
        $member = VaultMember::where('vault_id', $vaultId)->where('user_id', $request->user()->id)->first();
        abort_if(! $member, 404);
        if ($needRole === 'admin') {
            abort_unless($member->role === 'admin', 403);
        } elseif ($needRole === 'write') {
            abort_unless(in_array($member->role, ['admin', 'write'], true), 403);
        }

        return $member;
    }

    /** Cliente-vault de um customer; garante membership. */
    private function clientVault(Request $request, int $customerId, ?string $needRole = null): EnvClientVault
    {
        $cv = EnvClientVault::where('customer_id', $customerId)->first();
        abort_if(! $cv, 404);
        $this->membershipByVault($request, $cv->vault_id, $needRole);

        return $cv;
    }

    // ── Clientes-vault ────────────────────────────────────────────────────────

    /** Clientes cujo cofre de ambientes o usuário é membro. */
    public function clients(Request $request): JsonResponse
    {
        $user = $this->guardInternal($request);

        $vaultIds = VaultMember::where('user_id', $user->id)->pluck('vault_id');
        $rows = EnvClientVault::with('customer:id,name,company_name')
            ->whereIn('vault_id', $vaultIds)
            ->get()
            ->map(fn ($cv) => [
                'customer_id'       => $cv->customer_id,
                'customer_name'     => $cv->customer?->name,
                'vault_id'          => $cv->vault_id,
                'environments_count' => EnvEnvironment::where('customer_id', $cv->customer_id)->count(),
                'role'              => VaultMember::where('vault_id', $cv->vault_id)->where('user_id', $user->id)->value('role'),
            ])
            ->sortBy('customer_name')->values();

        return response()->json($rows);
    }

    /**
     * Cria o cofre de ambientes de um cliente: vault type='client' + member owner +
     * mapeamento. O client já envia a vaultKey cifrada com a PRÓPRIA chave pública.
     */
    public function createClient(Request $request): JsonResponse
    {
        $user = $this->guardInternal($request);
        $data = $request->validate([
            'customer_id'         => 'required|integer|exists:customers,id',
            'encrypted_vault_key' => 'required|string|max:2000',
        ]);

        if (EnvClientVault::where('customer_id', $data['customer_id'])->exists()) {
            return response()->json(['message' => 'Este cliente já tem cofre de ambientes.'], 409);
        }
        $customer = Customer::findOrFail($data['customer_id']);

        $cv = DB::transaction(function () use ($user, $customer, $data) {
            $vault = Vault::create([
                'type'       => 'client',
                'name'       => 'Ambientes — ' . $customer->name,
                'created_by' => $user->id,
            ]);
            VaultMember::create([
                'vault_id'            => $vault->id,
                'user_id'             => $user->id,
                'role'                => 'admin',
                'encrypted_vault_key' => $data['encrypted_vault_key'],
                'key_version'         => $vault->key_version ?? 1,
            ]);

            return EnvClientVault::create([
                'customer_id' => $customer->id,
                'vault_id'    => $vault->id,
                'created_by'  => $user->id,
            ]);
        });

        EnvAccessLog::record($request, 'client_create', ['item_label' => $customer->name]);

        return response()->json(['customer_id' => $cv->customer_id, 'vault_id' => $cv->vault_id], 201);
    }

    // ── Ambientes ─────────────────────────────────────────────────────────────

    public function environments(Request $request, int $customerId): JsonResponse
    {
        $this->guardInternal($request);
        $cv = $this->clientVault($request, $customerId);

        $rows = EnvEnvironment::withCount(['credentials', 'secrets'])
            ->where('customer_id', $customerId)
            ->orderByRaw("array_position(ARRAY['prod','homolog','dev','dr']::text[], type)")
            ->orderBy('name')
            ->get()
            ->map(fn ($e) => [
                'id'                => $e->id,
                'name'              => $e->name,
                'type'              => $e->type,
                'status'            => $e->status,
                'credentials_count' => $e->credentials_count,
                'vault_id'          => $e->vault_id,
            ]);

        return response()->json(['vault_id' => $cv->vault_id, 'environments' => $rows]);
    }

    public function storeEnvironment(Request $request, int $customerId): JsonResponse
    {
        $this->guardInternal($request);
        $cv = $this->clientVault($request, $customerId, 'write');
        $data = $request->validate([
            'name'                => 'required|string|max:120',
            'type'                => 'required|in:prod,homolog,dev,dr',
            'status'              => 'sometimes|in:online,offline,unknown,maintenance',
            'inventory'           => 'sometimes|array',
            'notes'               => 'nullable|string|max:5000',
            'responsible_user_id' => 'nullable|integer|exists:users,id',
        ]);

        $env = EnvEnvironment::create(array_merge($data, [
            'customer_id' => $customerId,
            'vault_id'    => $cv->vault_id,
            'status'      => $data['status'] ?? 'unknown',
        ]));
        EnvAccessLog::record($request, 'env_create', ['environment_id' => $env->id, 'item_label' => $env->name]);

        return response()->json(['id' => $env->id], 201);
    }

    private function envWithMembership(Request $request, int $envId, ?string $needRole = null): EnvEnvironment
    {
        $env = EnvEnvironment::findOrFail($envId);
        $this->membershipByVault($request, $env->vault_id, $needRole);

        return $env;
    }

    public function showEnvironment(Request $request, int $envId): JsonResponse
    {
        $this->guardInternal($request);
        $env = $this->envWithMembership($request, $envId);

        return response()->json([
            'id'          => $env->id,
            'customer_id' => $env->customer_id,
            'vault_id'    => $env->vault_id,
            'name'        => $env->name,
            'type'        => $env->type,
            'status'      => $env->status,
            'inventory'   => $env->inventory,
            'notes'       => $env->notes,
            'responsible' => $env->responsible?->only(['id', 'name']),
        ]);
    }

    public function updateEnvironment(Request $request, int $envId): JsonResponse
    {
        $this->guardInternal($request);
        $env = $this->envWithMembership($request, $envId, 'write');
        $data = $request->validate([
            'name'                => 'sometimes|string|max:120',
            'type'                => 'sometimes|in:prod,homolog,dev,dr',
            'status'              => 'sometimes|in:online,offline,unknown,maintenance',
            'inventory'           => 'sometimes|array',
            'notes'               => 'nullable|string|max:5000',
            'responsible_user_id' => 'nullable|integer|exists:users,id',
        ]);
        $env->update($data);
        EnvAccessLog::record($request, 'env_update', ['environment_id' => $env->id, 'item_label' => $env->name]);

        return response()->json(['updated' => true]);
    }

    public function destroyEnvironment(Request $request, int $envId): JsonResponse
    {
        $this->guardInternal($request);
        $env = $this->envWithMembership($request, $envId, 'admin');
        EnvAccessLog::record($request, 'env_delete', ['environment_id' => $env->id, 'item_label' => $env->name]);
        $env->delete();

        return response()->json(['deleted' => true]);
    }

    // ── Dashboard e busca ─────────────────────────────────────────────────────

    /** Indicadores do cofre (só sobre metadados CLARO; nunca toca segredos). */
    public function dashboard(Request $request): JsonResponse
    {
        $user = $this->guardInternal($request);

        $vaultIds = VaultMember::where('user_id', $user->id)->pluck('vault_id');
        $clientVaultIds = EnvClientVault::whereIn('vault_id', $vaultIds)->pluck('vault_id');
        $envIds = EnvEnvironment::whereIn('vault_id', $clientVaultIds)->pluck('id');

        $criticos = \App\Models\EnvCredential::whereIn('environment_id', $envIds)->where('critical', true)->count()
            + \App\Models\EnvDatabase::whereIn('environment_id', $envIds)->where('critical', true)->count()
            + \App\Models\EnvVpn::whereIn('environment_id', $envIds)->where('critical', true)->count()
            + \App\Models\EnvCertificate::whereIn('environment_id', $envIds)->where('critical', true)->count();

        $certsVencendo = \App\Models\EnvCertificate::whereIn('environment_id', $envIds)
            ->whereNotNull('valid_to')
            ->whereBetween('valid_to', [now()->startOfDay(), now()->addDays(30)])
            ->count();

        // Compartilhados = cliente-vaults com mais de 1 membro
        $compartilhados = VaultMember::whereIn('vault_id', $clientVaultIds)
            ->selectRaw('vault_id')->groupBy('vault_id')->havingRaw('count(*) > 1')->get()->count();

        $ultimoAcesso = EnvAccessLog::where('user_id', $user->id)->max('created_at');

        return response()->json([
            'clientes'       => $clientVaultIds->count(),
            'ambientes'      => $envIds->count(),
            'credenciais'    => \App\Models\EnvCredential::whereIn('environment_id', $envIds)->count(),
            'certificados'   => \App\Models\EnvCertificate::whereIn('environment_id', $envIds)->count(),
            'vpns'           => \App\Models\EnvVpn::whereIn('environment_id', $envIds)->count(),
            'itens_criticos' => $criticos,
            'compartilhados' => $compartilhados,
            'alertas'        => $certsVencendo,
            'ultimo_acesso'  => $ultimoAcesso,
        ]);
    }

    /**
     * Alertas de vencimento (só metadados CLARO): certificados vencendo/vencidos e
     * senhas com rotação vencida/próxima. Escopo = ambientes onde o user é membro.
     */
    public function alerts(Request $request): JsonResponse
    {
        $user = $this->guardInternal($request);
        $days = (int) $request->query('days', 30);
        $limit = now()->addDays($days)->endOfDay();

        $vaultIds = VaultMember::where('user_id', $user->id)->pluck('vault_id');
        $envIds = EnvEnvironment::whereIn('vault_id', $vaultIds)->pluck('id');

        $certificates = \App\Models\EnvCertificate::with('environment:id,name,customer_id', 'environment.customer:id,name')
            ->whereIn('environment_id', $envIds)
            ->whereNotNull('valid_to')
            ->where('valid_to', '<=', $limit)
            ->orderBy('valid_to')
            ->get()
            ->map(fn ($c) => [
                'id'             => $c->id,
                'name'           => $c->name,
                'valid_to'       => $c->valid_to?->toDateString(),
                'days_to_expire' => (int) round(now()->startOfDay()->diffInDays($c->valid_to, false)),
                'environment_id' => $c->environment_id,
                'environment'    => $c->environment?->name,
                'customer'       => $c->environment?->customer?->name,
            ]);

        // Senhas: rotação vencida/próxima (last_rotated_at + rotate_every_days).
        $passwords = \App\Models\EnvCredential::with('environment:id,name')
            ->whereIn('environment_id', $envIds)
            ->whereNotNull('rotate_every_days')
            ->whereNotNull('last_rotated_at')
            ->get()
            ->map(function ($c) {
                $next = $c->last_rotated_at->copy()->addDays($c->rotate_every_days);

                return [
                    'id'             => $c->id,
                    'label'          => $c->label,
                    'next_rotation'  => $next->toDateString(),
                    'days_to_expire' => (int) round(now()->startOfDay()->diffInDays($next, false)),
                    'environment_id' => $c->environment_id,
                    'environment'    => $c->environment?->name,
                ];
            })
            ->filter(fn ($p) => $p['days_to_expire'] <= $days)
            ->sortBy('days_to_expire')->values();

        return response()->json([
            'certificates' => $certificates,
            'passwords'    => $passwords,
        ]);
    }

    /** Busca por METADADOS CLARO (nome de ambiente, credencial, host de banco/vpn). */
    public function search(Request $request): JsonResponse
    {
        $user = $this->guardInternal($request);
        $q = trim((string) $request->query('q'));
        if (mb_strlen($q) < 2) {
            return response()->json(['environments' => [], 'credentials' => [], 'resources' => []]);
        }
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';

        $vaultIds = VaultMember::where('user_id', $user->id)->pluck('vault_id');
        $envIds = EnvEnvironment::whereIn('vault_id', $vaultIds)->pluck('id');

        $environments = EnvEnvironment::with('customer:id,name')
            ->whereIn('vault_id', $vaultIds)->where('name', 'ilike', $like)->limit(20)->get()
            ->map(fn ($e) => ['id' => $e->id, 'name' => $e->name, 'type' => $e->type, 'customer' => $e->customer?->name]);

        $credentials = \App\Models\EnvCredential::with('environment:id,name')
            ->whereIn('environment_id', $envIds)
            ->where(fn ($w) => $w->where('label', 'ilike', $like)->orWhere('username', 'ilike', $like))
            ->limit(20)->get()
            ->map(fn ($c) => ['id' => $c->id, 'label' => $c->label, 'username' => $c->username, 'environment_id' => $c->environment_id, 'environment' => $c->environment?->name]);

        return response()->json([
            'environments' => $environments,
            'credentials'  => $credentials,
        ]);
    }
}
