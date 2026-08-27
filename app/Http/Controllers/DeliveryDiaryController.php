<?php

namespace App\Http\Controllers;

use App\Models\DeliveryDiaryEntry;
use App\Models\StageDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Diário da Atividade (por entrega do cronograma). Interno — as rotas ficam no grupo
 * `block.cliente`, então o cliente nunca chega aqui. Anexos vão pela infra FASE 11
 * (POST /attachments com entity_type=DELIVERY_DIARY_ENTRY, entity_id=<entry.id>).
 */
class DeliveryDiaryController extends Controller
{
    public function index(StageDelivery $delivery): JsonResponse
    {
        $entries = DeliveryDiaryEntry::where('delivery_id', $delivery->id)
            ->with(['user:id,name', 'attachments'])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get()
            ->map(fn (DeliveryDiaryEntry $e) => $this->present($e));

        return response()->json(['items' => $entries]);
    }

    public function store(StageDelivery $delivery, Request $request): JsonResponse
    {
        $data = $request->validate([
            'body' => 'nullable|string|max:10000',
        ]);

        $body = trim((string) ($data['body'] ?? ''));

        $entry = DeliveryDiaryEntry::create([
            'delivery_id' => $delivery->id,
            'user_id'     => Auth::id(),
            'body'        => $body !== '' ? $body : null,
        ]);

        $entry->load(['user:id,name', 'attachments']);

        return response()->json($this->present($entry), 201);
    }

    public function destroy(DeliveryDiaryEntry $entry): JsonResponse
    {
        $user = Auth::user();
        // Autor pode apagar a própria nota; admin/coordenador podem moderar.
        if ((int) $entry->user_id !== (int) $user->id && !$user->isAdmin() && !$user->isCoordenador()) {
            return response()->json(['message' => 'Sem permissão para excluir esta nota.'], 403);
        }

        $entry->delete();

        return response()->json(['deleted' => true]);
    }

    private function present(DeliveryDiaryEntry $e): array
    {
        return [
            'id'          => $e->id,
            'body'        => $e->body,
            'created_at'  => optional($e->created_at)->toIso8601String(),
            'user'        => $e->user ? ['id' => $e->user->id, 'name' => $e->user->name] : null,
            'attachments' => $e->attachments->map(fn ($a) => [
                'id'   => $a->id,
                'name' => $a->original_name,
                'mime' => $a->mime_type,
            ])->values(),
        ];
    }
}
