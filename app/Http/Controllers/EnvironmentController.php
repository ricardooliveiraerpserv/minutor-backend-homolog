<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\EnvAccessLog;
use App\Models\EnvClientVault;
use App\Models\EnvEnvironment;
use App\Models\EnvFavorite;
use App\Models\PermissionGroup;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultMember;
use App\Models\VaultUserKey;
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

    /**
     * Pastas (cofres) de cliente visíveis ao usuário.
     * - Admin do Cofre (type=admin OU environments.admin): vê TODAS as pastas, mesmo as
     *   que ainda não é membro (cofre é interno — admin == super-admin). As que ele ainda
     *   não tem a chave vêm com is_member=false/pending_access=true (o auto-backfill as
     *   libera assim que qualquer membro abre o cofre).
     * - Demais usuários: só as pastas onde é membro (alocação).
     */
    public function clients(Request $request): JsonResponse
    {
        $user = $this->guardInternal($request);
        $isCofreAdmin = $user->type === 'admin' || $user->hasAccess('environments.admin');

        $myMembership = VaultMember::where('user_id', $user->id)
            ->get(['vault_id', 'role', 'encrypted_vault_key'])
            ->keyBy('vault_id');

        $query = EnvClientVault::with('customer:id,name,company_name');
        if (! $isCofreAdmin) {
            $query->whereIn('vault_id', $myMembership->keys());
        }

        $rows = $query->get()
            ->map(function ($cv) use ($myMembership) {
                $m = $myMembership->get($cv->vault_id);
                return [
                    'customer_id'        => $cv->customer_id,
                    'customer_name'      => $cv->customer?->name,
                    'vault_id'           => $cv->vault_id,
                    'environments_count' => EnvEnvironment::where('customer_id', $cv->customer_id)->count(),
                    'role'               => $m?->role,
                    // chave do PRÓPRIO usuário (opaca) — usada no front p/ re-embrulhar pros admins (backfill).
                    // null quando é admin que ainda não é membro: vê a pasta, mas sem a chave p/ decifrar.
                    'encrypted_vault_key' => $m?->encrypted_vault_key,
                    'is_member'          => (bool) $m,
                    'pending_access'     => ! $m, // admin não-membro: acesso será liberado pelo auto-backfill
                ];
            })
            ->sortBy('customer_name')->values();

        return response()->json($rows);
    }

    /**
     * Cria o cofre de ambientes de um cliente: vault type='client' + member owner +
     * mapeamento. O client já envia a vaultKey cifrada com a PRÓPRIA chave pública.
     */
    /**
     * Exclui a pasta (cofre) de um cliente inteira: ambientes (soft-delete, recuperável) +
     * estrutura do cofre (vault, membros, mapping). Só Admin do Cofre ou membro-admin da pasta.
     * ATENÇÃO: os ambientes são compartilhados com o Prosight (mesma tabela) — somem de lá também.
     */
    public function destroyClient(Request $request, int $customerId): JsonResponse
    {
        $user = $this->guardInternal($request);
        $cv = EnvClientVault::where('customer_id', $customerId)->first();
        abort_if(! $cv, 404);

        // Admin do Cofre (type=admin OU environments.admin) pode excluir qualquer pasta;
        // caso contrário exige ser membro-admin da pasta.
        $isCofreAdmin = $user->type === 'admin' || $user->hasAccess('environments.admin');
        if (! $isCofreAdmin) {
            $this->membershipByVault($request, $cv->vault_id, 'admin');
        }

        $customerName = Customer::find($customerId)?->name;
        $vaultId = $cv->vault_id;

        DB::transaction(function () use ($cv, $vaultId) {
            // Ambientes: soft-delete (recuperável). Some do Cofre e do Prosight (tabela compartilhada).
            EnvEnvironment::where('vault_id', $vaultId)->get()->each(fn ($e) => $e->delete());
            // Estrutura do cofre da pasta (chaves/mapeamento) — remoção definitiva.
            VaultMember::where('vault_id', $vaultId)->delete();
            $cv->delete();
            Vault::where('id', $vaultId)->delete();
        });

        EnvAccessLog::record($request, 'client_delete', ['item_label' => $customerName ?? (string) $customerId]);

        return response()->json(['deleted' => true]);
    }

    public function createClient(Request $request): JsonResponse
    {
        $user = $this->guardInternal($request);
        $data = $request->validate([
            'customer_id'                => 'required|integer|exists:customers,id',
            'encrypted_vault_key'        => 'required|string|max:2000',
            // Escrow corporativo (opcional): vaultKey cifrado pra chave da empresa.
            // Permite reabrir a pasta mesmo que todos percam master password + recovery key.
            'escrow_encrypted_vault_key' => 'nullable|string|max:2000',
            // "Admin vê tudo": vaultKey cifrada p/ a chave pública de cada admin do cofre,
            // pra que todo admin já entre como membro da pasta (zero-knowledge preservado).
            'members'                    => 'nullable|array',
            'members.*.user_id'              => 'required|integer',
            'members.*.encrypted_vault_key'  => 'required|string|max:2000',
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

            // "Admin vê tudo": entra cada admin do cofre como membro (só os que são admin de fato).
            $adminIds = $this->cofreAdminUserIds();
            foreach (($data['members'] ?? []) as $m) {
                if ((int) $m['user_id'] === $user->id) continue;               // criador já é membro
                if (! in_array((int) $m['user_id'], $adminIds, true)) continue; // só admins do cofre
                VaultMember::firstOrCreate(
                    ['vault_id' => $vault->id, 'user_id' => (int) $m['user_id']],
                    ['role' => 'admin', 'encrypted_vault_key' => $m['encrypted_vault_key'], 'key_version' => $vault->key_version ?? 1]
                );
            }

            return EnvClientVault::create([
                'customer_id'                => $customer->id,
                'vault_id'                   => $vault->id,
                'created_by'                 => $user->id,
                'escrow_encrypted_vault_key' => $data['escrow_encrypted_vault_key'] ?? null,
            ]);
        });

        EnvAccessLog::record($request, 'client_create', ['item_label' => $customer->name]);

        return response()->json(['customer_id' => $cv->customer_id, 'vault_id' => $cv->vault_id], 201);
    }

    /**
     * Backfill do escrow de uma pasta JÁ existente: quem é membro admin (tem o vaultKey)
     * cifra o vaultKey pra chave pública da empresa e grava aqui. Idempotente.
     */
    public function setEscrow(Request $request, int $customerId): JsonResponse
    {
        $cv = $this->clientVault($request, $customerId, 'admin');
        $data = $request->validate(['escrow_encrypted_vault_key' => 'required|string|max:2000']);
        $cv->update(['escrow_encrypted_vault_key' => $data['escrow_encrypted_vault_key']]);
        EnvAccessLog::record($request, 'client_escrow_set');

        return response()->json(['ok' => true]);
    }

    // ── Admins do Cofre (papel de MÓDULO, escopado ao cofre) ───────────────────
    // Papel environments.admin via grupo de permissão canônico. NÃO é admin do Minutor.
    // Só o admin do Minutor (type=admin) elege/remove admins do cofre.
    private const COFRE_ADMIN_GROUP = 'Admin do Cofre';

    private function requireMinutorAdmin(Request $request): User
    {
        $user = $this->guardInternal($request);
        abort_unless($user->type === 'admin', 403);
        return $user;
    }

    private function cofreAdminGroup(): PermissionGroup
    {
        return PermissionGroup::firstOrCreate(
            ['name' => self::COFRE_ADMIN_GROUP],
            [
                'description' => 'Admins do Cofre de Senhas/Ambientes. NÃO torna admin do Minutor.',
                'permissions' => ['vault.use', 'environments.use', 'environments.admin'],
            ]
        );
    }

    /** IDs de todos os admins do cofre = admins do Minutor + membros do grupo canônico. */
    private function cofreAdminUserIds(): array
    {
        $groupIds = $this->cofreAdminGroup()->users()->pluck('users.id')->all();
        $minutorAdmins = User::where('type', 'admin')->whereNull('customer_id')->pluck('id')->all();

        return array_values(array_unique(array_merge($groupIds, $minutorAdmins)));
    }

    /**
     * Chaves públicas dos admins do cofre (só quem já configurou o cofre pessoal).
     * O front usa pra embrulhar a vaultKey de cada pasta e tornar todo admin membro.
     */
    public function adminKeys(Request $request): JsonResponse
    {
        $this->guardInternal($request);
        $rows = VaultUserKey::whereIn('user_id', $this->cofreAdminUserIds())
            ->whereNotNull('public_key')
            ->with('user:id,name')
            ->get()
            ->map(fn ($k) => ['user_id' => $k->user_id, 'name' => $k->user?->name, 'public_key' => $k->public_key])
            ->values();

        return response()->json($rows);
    }

    /**
     * Backfill "admin vê tudo": adiciona admins como membros de uma pasta JÁ existente.
     * Quem chama tem que ser membro (tem a vaultKey) e só entra quem é admin do cofre.
     */
    public function shareWithAdmins(Request $request, int $customerId): JsonResponse
    {
        $cv = $this->clientVault($request, $customerId); // 404 se o chamador não é membro
        $data = $request->validate([
            'members'                        => 'required|array',
            'members.*.user_id'              => 'required|integer',
            'members.*.encrypted_vault_key'  => 'required|string|max:2000',
        ]);
        $adminIds = $this->cofreAdminUserIds();
        $vault = Vault::find($cv->vault_id);
        $added = 0;
        foreach ($data['members'] as $m) {
            if (! in_array((int) $m['user_id'], $adminIds, true)) continue;
            $r = VaultMember::firstOrCreate(
                ['vault_id' => $cv->vault_id, 'user_id' => (int) $m['user_id']],
                ['role' => 'admin', 'encrypted_vault_key' => $m['encrypted_vault_key'], 'key_version' => $vault->key_version ?? 1]
            );
            if ($r->wasRecentlyCreated) $added++;
        }
        EnvAccessLog::record($request, 'client_share_admins', ['item_label' => (string) $added]);

        return response()->json(['added' => $added]);
    }

    /** Lista usuários internos com os flags de admin do cofre / admin do Minutor. */
    public function cofreAdmins(Request $request): JsonResponse
    {
        $this->requireMinutorAdmin($request);
        $group = $this->cofreAdminGroup();
        $adminIds = $group->users()->pluck('users.id')->all();

        $users = User::whereNull('customer_id')->orderBy('name')
            ->get(['id', 'name', 'email', 'type'])
            ->map(fn ($u) => [
                'id'               => $u->id,
                'name'             => $u->name,
                'email'            => $u->email,
                'is_minutor_admin' => $u->type === 'admin',
                'is_cofre_admin'   => $u->type === 'admin' || in_array($u->id, $adminIds, true),
                'can_toggle'       => $u->type !== 'admin', // admin do Minutor já tem tudo
            ])->values();

        return response()->json($users);
    }

    /** Concede/remove o papel de admin do cofre (grupo canônico). Só admin do Minutor. */
    public function setCofreAdmin(Request $request): JsonResponse
    {
        $this->requireMinutorAdmin($request);
        $data = $request->validate(['user_id' => 'required|exists:users,id', 'grant' => 'required|boolean']);
        $group = $this->cofreAdminGroup();

        if ($data['grant']) {
            $group->users()->syncWithoutDetaching([$data['user_id']]);
        } else {
            $group->users()->detach($data['user_id']);
        }
        EnvAccessLog::record($request, $data['grant'] ? 'cofre_admin_grant' : 'cofre_admin_revoke', ['item_label' => (string) $data['user_id']]);

        return response()->json(['ok' => true]);
    }

    /**
     * Recuperação pela chave da empresa (admin do cofre): lista as pastas com o blob de escrow,
     * pra o front decifrar com a privada da empresa e re-compartilhar com a chave nova de cada um.
     */
    public function escrowRecovery(Request $request): JsonResponse
    {
        $user = $this->guardInternal($request);
        abort_unless($user->hasAccess('environments.admin'), 403);

        $rows = EnvClientVault::with('customer:id,name')->get()->map(fn ($cv) => [
            'customer_id'                => $cv->customer_id,
            'customer_name'              => $cv->customer?->name,
            'vault_id'                   => $cv->vault_id,
            'has_escrow'                 => ! empty($cv->escrow_encrypted_vault_key),
            'escrow_encrypted_vault_key' => $cv->escrow_encrypted_vault_key,
        ])->sortBy('customer_name')->values();

        return response()->json($rows);
    }

    // ── Ambientes ─────────────────────────────────────────────────────────────

    public function environments(Request $request, int $customerId): JsonResponse
    {
        $this->guardInternal($request);
        $cv = $this->clientVault($request, $customerId);

        $favIds = EnvFavorite::where('user_id', $request->user()->id)
            ->whereIn('environment_id', EnvEnvironment::where('customer_id', $customerId)->pluck('id'))
            ->pluck('environment_id')->flip();

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
                'is_favorite'       => $favIds->has($e->id),
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
            'rdp_host'            => 'nullable|string|max:255',
            'rdp_port'            => 'nullable|integer|min:1|max:65535',
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
        \App\Services\EnvAccess::authorize($request->user(), $env, 'view');

        return response()->json([
            'id'          => $env->id,
            'customer_id' => $env->customer_id,
            'vault_id'    => $env->vault_id,
            'name'        => $env->name,
            'type'        => $env->type,
            'status'      => $env->status,
            'inventory'   => $env->inventory,
            'notes'       => $env->notes,
            'block_notes' => $env->block_notes,
            'rdp_host'    => $env->rdp_host,
            'rdp_port'    => $env->rdp_port,
            'responsible' => $env->responsible?->only(['id', 'name']),
            'is_favorite' => EnvFavorite::where('user_id', $request->user()->id)->where('environment_id', $env->id)->exists(),
            // Permissões efetivas DESTE usuário no ambiente (o FE esconde botões).
            'permissions' => \App\Services\EnvAccess::effectiveFor($request->user(), $env),
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
            'rdp_host'            => 'nullable|string|max:255',
            'rdp_port'            => 'nullable|integer|min:1|max:65535',
            // Texto livre por bloco: { credentials, databases, appservers, vpns, certificates, links }
            'block_notes'         => 'nullable|array',
            'block_notes.*'       => 'nullable|string|max:5000',
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

    // ── Compartilhamento (membros do cofre do cliente) ────────────────────────

    /**
     * Membros do cofre do cliente. Adicionar/listar/mudar papel reusam os endpoints
     * `/vault/*` (funcionam em type='client'). AQUI só a REMOÇÃO é aditiva: revoga a
     * membership sem marcar `pending_rotation` — o `rotate` padrão só cobre vault_items
     * e corromperia os env_secrets. O acesso já é cortado pelo gate de membership do
     * reveal. A rotação de chave própria (recifra env_secrets) fica p/ a próxima fase.
     */
    public function removeMember(Request $request, int $customerId, int $userId): JsonResponse
    {
        $this->guardInternal($request);
        $cv = $this->clientVault($request, $customerId, 'admin');

        $target = VaultMember::where('vault_id', $cv->vault_id)->where('user_id', $userId)->firstOrFail();
        if ($target->role === 'admin') {
            $admins = VaultMember::where('vault_id', $cv->vault_id)->where('role', 'admin')->count();
            abort_if($admins <= 1, 422, 'O cofre precisa de ao menos um administrador.');
        }
        $target->delete();
        EnvAccessLog::record($request, 'member_remove', ['item_label' => 'user #' . $userId]);

        return response()->json(['removed' => true]);
    }

    // ── Favoritos (acesso rápido) ─────────────────────────────────────────────

    /** Alterna o ambiente como favorito do usuário. Basta ser membro (view). */
    public function toggleFavorite(Request $request, int $envId): JsonResponse
    {
        $this->guardInternal($request);
        $env = $this->envWithMembership($request, $envId);
        \App\Services\EnvAccess::authorize($request->user(), $env, 'view');

        $existing = EnvFavorite::where('user_id', $request->user()->id)->where('environment_id', $env->id)->first();
        if ($existing) {
            $existing->delete();

            return response()->json(['favorited' => false]);
        }
        EnvFavorite::create(['user_id' => $request->user()->id, 'environment_id' => $env->id]);

        return response()->json(['favorited' => true]);
    }

    /** Ambientes favoritados do usuário (para o acesso rápido na home). */
    public function favorites(Request $request): JsonResponse
    {
        $user = $this->guardInternal($request);
        $vaultIds = VaultMember::where('user_id', $user->id)->pluck('vault_id');

        $rows = EnvFavorite::with('environment.customer:id,name')
            ->where('user_id', $user->id)
            ->get()
            ->filter(fn ($f) => $f->environment && $vaultIds->contains($f->environment->vault_id))
            ->map(fn ($f) => [
                'id'       => $f->environment->id,
                'name'     => $f->environment->name,
                'type'     => $f->environment->type,
                'status'   => $f->environment->status,
                'customer' => $f->environment->customer?->name,
            ])
            ->sortBy('customer')->values();

        return response()->json($rows);
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
