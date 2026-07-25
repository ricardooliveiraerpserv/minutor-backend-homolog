<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Vault;
use App\Models\VaultAccessLog;
use App\Models\VaultMember;
use App\Models\VaultUserKey;
use App\Services\Totp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Cofres e membros. Blobs (encrypted_vault_key, data) são opacos — cifrados no client.
 * Operações destrutivas exigem STEP-UP: totp_code fresco no payload (limita dano de
 * um token Sanctum roubado). NUNCA logar payloads.
 */
class VaultController extends Controller
{
    private const INTERNAL_TYPES = ['admin', 'administrativo', 'coordenador', 'consultor'];

    private function guardInternal(Request $request): User
    {
        $user = $request->user();
        abort_unless(in_array($user->effectiveType(), self::INTERNAL_TYPES, true), 403);

        return $user;
    }

    /** Membership do user no cofre (404 se não é membro — não revelar existência). */
    private function membership(Request $request, int $vaultId, ?string $needRole = null): VaultMember
    {
        $member = VaultMember::with('vault')
            ->where('vault_id', $vaultId)
            ->where('user_id', $request->user()->id)
            ->first();
        abort_if(! $member, 404);
        if ($needRole === 'admin') {
            abort_unless($member->isVaultAdmin(), 403);
        } elseif ($needRole === 'write') {
            abort_unless($member->canWrite(), 403);
        }

        return $member;
    }

    /** Step-up para operações destrutivas: Microsoft (stepup_token) ou TOTP (totp_code). */
    private function requireFreshTotp(Request $request): void
    {
        $keys = VaultUserKey::where('user_id', $request->user()->id)->first();

        if (\App\Services\VaultStepUp::driver() === 'microsoft') {
            if (! \App\Services\VaultStepUp::check($keys, (string) $request->input('stepup_token'))) {
                abort(422, 'Verificação Microsoft necessária para esta operação.');
            }

            return;
        }

        $code = (string) $request->input('totp_code');
        $timestep = ($keys?->totpConfirmed() && $keys->totp_secret && $code !== '')
            ? Totp::verify($keys->totp_secret, $code, $keys->totp_last_timestep)
            : null;
        if ($timestep === null) {
            abort(422, 'Código do autenticador necessário para esta operação.');
        }
        $keys->forceFill(['totp_last_timestep' => $timestep])->save();
    }

    // ── Cofres ───────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $user = $this->guardInternal($request);

        $rows = VaultMember::with('vault')
            ->where('user_id', $user->id)
            ->get()
            ->filter(fn ($m) => $m->vault !== null)
            ->map(fn ($m) => [
                'id'                  => $m->vault->id,
                'type'                => $m->vault->type,
                'name'                => $m->vault->name,
                'role'                => $m->role,
                'key_version'         => $m->vault->key_version,
                'pending_rotation'    => $m->vault->pending_rotation,
                'encrypted_vault_key' => $m->encrypted_vault_key,
                'member_key_version'  => $m->key_version,
                'items_count'         => $m->vault->items()->count(),
                'members_count'       => $m->vault->members()->count(),
            ])
            ->sortBy([['type', 'desc'], ['name', 'asc']]) // personal primeiro, depois por nome
            ->values();

        return response()->json($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->guardInternal($request);
        $data = $request->validate([
            'name'                => 'required|string|max:120',
            'encrypted_vault_key' => 'required|string|max:2000',
        ]);

        $vault = DB::transaction(function () use ($user, $data) {
            $vault = Vault::create(['type' => 'shared', 'name' => $data['name'], 'created_by' => $user->id]);
            VaultMember::create([
                'vault_id'            => $vault->id,
                'user_id'             => $user->id,
                'role'                => 'admin',
                'encrypted_vault_key' => $data['encrypted_vault_key'],
            ]);

            return $vault;
        });

        VaultAccessLog::record($request, 'vault_create', ['vault_id' => $vault->id]);

        return response()->json(['id' => $vault->id], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->guardInternal($request);
        $member = $this->membership($request, $id, 'admin');
        $data = $request->validate(['name' => 'required|string|max:120']);

        abort_if($member->vault->type === 'personal', 422, 'Cofre pessoal não pode ser renomeado.');
        $member->vault->update(['name' => $data['name']]);
        VaultAccessLog::record($request, 'vault_update', ['vault_id' => $id]);

        return response()->json(['updated' => true]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->guardInternal($request);
        $member = $this->membership($request, $id, 'admin');
        abort_if($member->vault->type === 'personal', 422, 'Cofre pessoal não pode ser excluído.');
        $this->requireFreshTotp($request);

        VaultAccessLog::record($request, 'vault_delete', ['vault_id' => $id], ['vault_name' => $member->vault->name]);
        $member->vault->delete(); // cascata apaga members/itens/logs FK->null

        return response()->json(['deleted' => true]);
    }

    // ── Membros ──────────────────────────────────────────────────────────────

    public function members(Request $request, int $id): JsonResponse
    {
        $this->guardInternal($request);
        $this->membership($request, $id);

        $rows = VaultMember::with('user:id,name,email')
            ->where('vault_id', $id)->get()
            ->map(fn ($m) => [
                'user_id'     => $m->user_id,
                'name'        => $m->user?->name,
                'email'       => $m->user?->email,
                'role'        => $m->role,
                'key_version' => $m->key_version,
            ]);

        return response()->json($rows);
    }

    public function addMember(Request $request, int $id): JsonResponse
    {
        $this->guardInternal($request);
        $member = $this->membership($request, $id, 'admin');
        abort_if($member->vault->type === 'personal', 422, 'Cofre pessoal não aceita membros.');
        $data = $request->validate([
            'user_id'             => 'required|integer|exists:users,id',
            'role'                => 'required|in:admin,write,read',
            'encrypted_vault_key' => 'required|string|max:2000',
        ]);

        // Alvo precisa ser interno e ter perfil cripto configurado (tem chave pública)
        $target = User::findOrFail($data['user_id']);
        abort_unless(in_array($target->effectiveType(), self::INTERNAL_TYPES, true), 422, 'Usuário não elegível.');
        $targetKeys = VaultUserKey::where('user_id', $target->id)->first();
        abort_unless($targetKeys?->isConfigured(), 422, 'Usuário ainda não configurou o cofre.');

        VaultMember::updateOrCreate(
            ['vault_id' => $id, 'user_id' => $target->id],
            ['role' => $data['role'], 'encrypted_vault_key' => $data['encrypted_vault_key'], 'key_version' => $member->vault->key_version]
        );
        VaultAccessLog::record($request, 'member_add', ['vault_id' => $id], ['target_user_id' => $target->id]);

        return response()->json(['added' => true], 201);
    }

    public function updateMember(Request $request, int $id, int $userId): JsonResponse
    {
        $this->guardInternal($request);
        $this->membership($request, $id, 'admin');
        $data = $request->validate(['role' => 'required|in:admin,write,read']);

        $target = VaultMember::where('vault_id', $id)->where('user_id', $userId)->firstOrFail();
        // Não deixar o cofre sem nenhum admin
        if ($target->role === 'admin' && $data['role'] !== 'admin') {
            $admins = VaultMember::where('vault_id', $id)->where('role', 'admin')->count();
            abort_if($admins <= 1, 422, 'O cofre precisa de ao menos um administrador.');
        }
        $target->update(['role' => $data['role']]);

        return response()->json(['updated' => true]);
    }

    public function removeMember(Request $request, int $id, int $userId): JsonResponse
    {
        $user = $this->guardInternal($request);
        $member = $this->membership($request, $id, 'admin');
        abort_if($member->vault->type === 'personal', 422, 'Cofre pessoal não tem membros removíveis.');
        $this->requireFreshTotp($request);

        $target = VaultMember::where('vault_id', $id)->where('user_id', $userId)->firstOrFail();
        if ($target->role === 'admin') {
            $admins = VaultMember::where('vault_id', $id)->where('role', 'admin')->count();
            abort_if($admins <= 1, 422, 'O cofre precisa de ao menos um administrador.');
        }

        DB::transaction(function () use ($member, $target) {
            $target->delete();
            // Ex-membro conhece a vault key antiga → marcar rotação pendente
            $member->vault->update(['pending_rotation' => true]);
        });
        VaultAccessLog::record($request, 'member_remove', ['vault_id' => $id], ['target_user_id' => $userId]);

        return response()->json(['removed' => true, 'pending_rotation' => true]);
    }

    /**
     * Rotação de chave (pós-remoção de membro): transação única com a NOVA vault key
     * re-wrapada p/ cada membro atual + TODOS os itens re-cifrados no client do admin.
     */
    public function rotate(Request $request, int $id): JsonResponse
    {
        $this->guardInternal($request);
        $member = $this->membership($request, $id, 'admin');
        $this->requireFreshTotp($request);

        $data = $request->validate([
            'new_key_version'               => 'required|integer',
            'members'                       => 'required|array|min:1',
            'members.*.user_id'             => 'required|integer',
            'members.*.encrypted_vault_key' => 'required|string|max:2000',
            'items'                         => 'present|array',
            'items.*.id'                    => 'required|integer',
            'items.*.data'                  => 'required|string|max:102400',
        ]);

        $vault = $member->vault;
        abort_unless($data['new_key_version'] === $vault->key_version + 1, 409, 'Versão de chave desatualizada — recarregue o cofre.');

        // Cobertura EXATA: todos os membros atuais e todos os itens vivos
        $currentMemberIds = VaultMember::where('vault_id', $id)->pluck('user_id')->sort()->values();
        $sentMemberIds = collect($data['members'])->pluck('user_id')->sort()->values();
        abort_unless($currentMemberIds->toArray() === $sentMemberIds->toArray(), 422, 'Lista de membros divergente — recarregue o cofre.');

        $currentItemIds = $vault->items()->pluck('id')->sort()->values();
        $sentItemIds = collect($data['items'])->pluck('id')->sort()->values();
        abort_unless($currentItemIds->toArray() === $sentItemIds->toArray(), 422, 'Lista de itens divergente — recarregue o cofre.');

        DB::transaction(function () use ($vault, $data) {
            foreach ($data['members'] as $m) {
                VaultMember::where('vault_id', $vault->id)->where('user_id', $m['user_id'])
                    ->update(['encrypted_vault_key' => $m['encrypted_vault_key'], 'key_version' => $data['new_key_version']]);
            }
            foreach ($data['items'] as $i) {
                $vault->items()->where('id', $i['id'])
                    ->update(['data' => $i['data'], 'key_version' => $data['new_key_version']]);
            }
            $vault->update(['key_version' => $data['new_key_version'], 'pending_rotation' => false]);
        });
        VaultAccessLog::record($request, 'key_rotate', ['vault_id' => $id], ['new_key_version' => $data['new_key_version']]);

        return response()->json(['rotated' => true, 'key_version' => $data['new_key_version']]);
    }

    // ── Auditoria ────────────────────────────────────────────────────────────

    public function logs(Request $request): JsonResponse
    {
        $user = $this->guardInternal($request);

        $query = VaultAccessLog::with(['user:id,name', 'vault:id,name'])->orderByDesc('created_at');

        // Admin global vê tudo; admin de cofre vê só os cofres que administra
        if (! $user->isAdmin()) {
            $adminVaultIds = VaultMember::where('user_id', $user->id)->where('role', 'admin')->pluck('vault_id');
            $query->whereIn('vault_id', $adminVaultIds);
        }

        foreach (['vault_id', 'user_id', 'action'] as $f) {
            if ($request->filled($f)) {
                $query->where($f, $request->query($f));
            }
        }
        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->query('to') . ' 23:59:59');
        }

        return response()->json($query->paginate((int) $request->query('per_page', 50)));
    }
}
