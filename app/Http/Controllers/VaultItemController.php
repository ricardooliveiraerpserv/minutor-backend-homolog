<?php

namespace App\Http\Controllers;

use App\Models\VaultAccessLog;
use App\Models\VaultItem;
use App\Models\VaultMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Itens do cofre. `data` é ciphertext do client (AES-GCM com a vault key) — o servidor
 * armazena e devolve o blob sem interpretá-lo. NUNCA logar `data`.
 */
class VaultItemController extends Controller
{
    private function membership(Request $request, int $vaultId, ?string $needRole = null): VaultMember
    {
        $member = VaultMember::with('vault')
            ->where('vault_id', $vaultId)
            ->where('user_id', $request->user()->id)
            ->first();
        abort_if(! $member, 404);
        if ($needRole === 'write') {
            abort_unless($member->canWrite(), 403);
        }

        return $member;
    }

    private function itemMembership(Request $request, int $itemId, ?string $needRole = null): array
    {
        $item = VaultItem::withTrashed()->findOrFail($itemId);
        $member = $this->membership($request, $item->vault_id, $needRole);

        return [$item, $member];
    }

    public function index(Request $request, int $vaultId): JsonResponse
    {
        $member = $this->membership($request, $vaultId);

        $query = VaultItem::where('vault_id', $vaultId);
        if ($request->boolean('trashed')) {
            // Lixeira: só quem pode escrever restaura/expurga
            abort_unless($member->canWrite(), 403);
            $query->onlyTrashed();
        }
        if ($request->filled('search')) {
            $query->where('name', 'ilike', '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $request->query('search')) . '%');
        }

        $rows = $query->orderBy('name')
            ->get(['id', 'type', 'name', 'data', 'key_version', 'updated_at', 'updated_by', 'deleted_at']);

        return response()->json($rows);
    }

    public function store(Request $request, int $vaultId): JsonResponse
    {
        $member = $this->membership($request, $vaultId, 'write');
        $data = $request->validate([
            'name'        => 'required|string|max:200',
            'type'        => 'sometimes|in:login,note',
            'data'        => 'required|string|max:102400',
            'key_version' => 'required|integer',
        ]);
        // Blob cifrado com chave antiga não entra (pós-rotação o client precisa re-sincronizar)
        abort_unless($data['key_version'] === $member->vault->key_version, 409, 'Chave do cofre rotacionada — recarregue o cofre.');

        $item = VaultItem::create([
            'vault_id'    => $vaultId,
            'type'        => $data['type'] ?? 'login',
            'name'        => $data['name'],
            'data'        => $data['data'],
            'key_version' => $data['key_version'],
            'created_by'  => $request->user()->id,
            'updated_by'  => $request->user()->id,
        ]);
        VaultAccessLog::record($request, 'item_create', ['vault_id' => $vaultId, 'item_id' => $item->id, 'item_name' => $item->name]);

        return response()->json(['id' => $item->id], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        [$item, $member] = $this->itemMembership($request, $id, 'write');
        $data = $request->validate([
            'name'        => 'required|string|max:200',
            'data'        => 'required|string|max:102400',
            'key_version' => 'required|integer',
        ]);
        abort_unless($data['key_version'] === $member->vault->key_version, 409, 'Chave do cofre rotacionada — recarregue o cofre.');

        $item->update([
            'name'        => $data['name'],
            'data'        => $data['data'],
            'key_version' => $data['key_version'],
            'updated_by'  => $request->user()->id,
        ]);
        VaultAccessLog::record($request, 'item_update', ['vault_id' => $item->vault_id, 'item_id' => $item->id, 'item_name' => $item->name]);

        return response()->json(['updated' => true]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        [$item] = $this->itemMembership($request, $id, 'write');
        $item->delete(); // soft delete (lixeira)
        VaultAccessLog::record($request, 'item_delete', ['vault_id' => $item->vault_id, 'item_id' => $item->id, 'item_name' => $item->name]);

        return response()->json(['deleted' => true]);
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        [$item] = $this->itemMembership($request, $id, 'write');
        $item->restore();
        VaultAccessLog::record($request, 'item_restore', ['vault_id' => $item->vault_id, 'item_id' => $item->id, 'item_name' => $item->name]);

        return response()->json(['restored' => true]);
    }

    /**
     * Auditoria client-iniciada de reveal/copy (o blob já foi entregue na listagem —
     * isto registra COMPORTAMENTO, não é enforcement; limitação documentada no plano).
     */
    public function logAction(Request $request, int $id): JsonResponse
    {
        [$item] = $this->itemMembership($request, $id);
        $data = $request->validate(['action' => 'required|in:reveal,copy,autofill_totp']);

        VaultAccessLog::record(
            $request,
            'item_' . ($data['action'] === 'autofill_totp' ? 'copy' : $data['action']),
            ['vault_id' => $item->vault_id, 'item_id' => $item->id, 'item_name' => $item->name],
            $data['action'] === 'autofill_totp' ? ['kind' => 'totp'] : []
        );

        return response()->json(['logged' => true]);
    }
}
