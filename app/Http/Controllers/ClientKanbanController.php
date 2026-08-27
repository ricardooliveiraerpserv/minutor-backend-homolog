<?php

namespace App\Http\Controllers;

use App\Models\KanbanBoard;
use App\Models\KanbanCard;
use App\Models\KanbanCardComment;
use App\Models\KanbanCardEvent;
use App\Models\KanbanCardFieldValue;
use App\Models\KanbanChecklistItem;
use App\Models\KanbanColumn;
use App\Models\KanbanField;
use App\Models\KanbanLabel;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Kanban do Cliente (Fase 1 / MVP). Tudo é escopado ao customer_id do usuário logado
 * (perfil cliente). Rotas ficam no grupo do Portal do Cliente. Anexos de card usam a
 * infra FASE 11 (entity_type = KANBAN_CARD) via os endpoints genéricos /attachments.
 */
class ClientKanbanController extends Controller
{
    private const PRIORITIES = ['low', 'medium', 'high'];

    // ─────────────────────────── helpers de escopo ───────────────────────────

    private function customerId(): int
    {
        $cid = Auth::user()->customer_id;
        abort_unless($cid, 403, 'Usuário não está vinculado a um cliente.');
        return (int) $cid;
    }

    /**
     * Escopo de acesso ao quadro (Fase 4): do customer logado E acessível ao usuário —
     * criador, OU quadro sem membros definidos (aberto a todos do cliente), OU o usuário
     * é membro. Reusado em boards/colunas/cards/etc. via where()/whereHas('board').
     */
    private function boardAccessScope(): \Closure
    {
        $uid = (int) Auth::id();
        $cid = $this->customerId();
        return function ($q) use ($uid, $cid) {
            $q->where('customer_id', $cid)->where(function ($qq) use ($uid) {
                $qq->where('created_by_user_id', $uid)
                   ->orWhereDoesntHave('members')
                   ->orWhereHas('members', fn ($m) => $m->where('users.id', $uid));
            });
        };
    }

    private function board(int $id): KanbanBoard
    {
        return KanbanBoard::where($this->boardAccessScope())->findOrFail($id);
    }

    private function column(int $id): KanbanColumn
    {
        return KanbanColumn::whereHas('board', $this->boardAccessScope())->findOrFail($id);
    }

    private function card(int $id): KanbanCard
    {
        return KanbanCard::whereHas('board', $this->boardAccessScope())->findOrFail($id);
    }

    // ─────────────────────────────── boards ──────────────────────────────────

    public function index(): JsonResponse
    {
        $boards = KanbanBoard::where($this->boardAccessScope())
            ->withCount(['columns', 'cards'])
            ->orderBy('position')->orderBy('id')
            ->get()
            ->map(fn (KanbanBoard $b) => [
                'id' => $b->id, 'name' => $b->name, 'description' => $b->description,
                'color' => $b->color, 'position' => $b->position,
                'columns_count' => $b->columns_count, 'cards_count' => $b->cards_count,
            ]);

        return response()->json(['items' => $boards]);
    }

    public function storeBoard(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'        => 'required|string|max:120',
            'description' => 'nullable|string|max:2000',
            'color'       => 'nullable|string|max:9',
        ]);

        $board = KanbanBoard::create([
            'customer_id'        => $this->customerId(),
            'created_by_user_id' => Auth::id(),
            'name'               => $data['name'],
            'description'        => $data['description'] ?? null,
            'color'              => $data['color'] ?? null,
            'position'           => (int) KanbanBoard::where('customer_id', $this->customerId())->max('position') + 1,
        ]);

        // Colunas iniciais padrão (o cliente pode renomear/excluir depois).
        $defaults = [['A Fazer', '#94a3b8'], ['Em Andamento', '#3b82f6'], ['Concluído', '#22c55e']];
        foreach ($defaults as $i => [$name, $color]) {
            $board->columns()->create(['name' => $name, 'color' => $color, 'position' => $i]);
        }

        return response()->json($this->boardFull($board->fresh()), 201);
    }

    public function showBoard(int $id): JsonResponse
    {
        return response()->json($this->boardFull($this->board($id)));
    }

    public function updateBoard(Request $request, int $id): JsonResponse
    {
        $board = $this->board($id);
        $data = $request->validate([
            'name'        => 'sometimes|required|string|max:120',
            'description' => 'nullable|string|max:2000',
            'color'       => 'nullable|string|max:9',
        ]);
        $board->update($data);
        return response()->json($this->boardFull($board->fresh()));
    }

    public function destroyBoard(int $id): JsonResponse
    {
        $this->board($id)->delete();
        return response()->json(['deleted' => true]);
    }

    /** Duplica um quadro como modelo: copia colunas e etiquetas (sem os cards). */
    public function duplicateBoard(int $id): JsonResponse
    {
        $src = $this->board($id);
        $copy = KanbanBoard::create([
            'customer_id'        => $this->customerId(),
            'created_by_user_id' => Auth::id(),
            'name'               => $src->name . ' (cópia)',
            'description'        => $src->description,
            'color'              => $src->color,
            'position'           => (int) KanbanBoard::where('customer_id', $this->customerId())->max('position') + 1,
        ]);
        foreach ($src->columns as $col) {
            $copy->columns()->create(['name' => $col->name, 'color' => $col->color, 'position' => $col->position]);
        }
        foreach ($src->labels as $lb) {
            $copy->labels()->create(['name' => $lb->name, 'color' => $lb->color]);
        }
        return response()->json($this->boardFull($copy->fresh()), 201);
    }

    // ─────────────────────────────── columns ─────────────────────────────────

    public function storeColumn(Request $request, int $boardId): JsonResponse
    {
        $board = $this->board($boardId);
        $data = $request->validate([
            'name'  => 'required|string|max:80',
            'color' => 'nullable|string|max:9',
        ]);
        $col = $board->columns()->create([
            'name' => $data['name'], 'color' => $data['color'] ?? null,
            'position' => (int) $board->columns()->max('position') + 1,
        ]);
        return response()->json($col, 201);
    }

    public function updateColumn(Request $request, int $id): JsonResponse
    {
        $col = $this->column($id);
        $col->update($request->validate([
            'name'  => 'sometimes|required|string|max:80',
            'color' => 'nullable|string|max:9',
        ]));
        return response()->json($col->fresh());
    }

    public function destroyColumn(int $id): JsonResponse
    {
        $this->column($id)->delete(); // cascade apaga os cards da coluna (FK)
        return response()->json(['deleted' => true]);
    }

    public function reorderColumns(Request $request, int $boardId): JsonResponse
    {
        $board = $this->board($boardId);
        $order = $request->validate(['order' => 'required|array', 'order.*' => 'integer'])['order'];
        $valid = $board->columns()->pluck('id')->all();
        foreach ($order as $pos => $colId) {
            if (in_array((int) $colId, $valid, true)) {
                KanbanColumn::where('id', $colId)->update(['position' => $pos]);
            }
        }
        return response()->json(['ok' => true]);
    }

    // ─────────────────────────────── labels ──────────────────────────────────

    public function storeLabel(Request $request, int $boardId): JsonResponse
    {
        $board = $this->board($boardId);
        $data = $request->validate(['name' => 'required|string|max:60', 'color' => 'nullable|string|max:9']);
        return response()->json($board->labels()->create($data), 201);
    }

    public function updateLabel(Request $request, int $id): JsonResponse
    {
        $label = KanbanLabel::whereHas('board', $this->boardAccessScope())->findOrFail($id);
        $label->update($request->validate(['name' => 'sometimes|required|string|max:60', 'color' => 'nullable|string|max:9']));
        return response()->json($label->fresh());
    }

    public function destroyLabel(int $id): JsonResponse
    {
        KanbanLabel::whereHas('board', $this->boardAccessScope())->findOrFail($id)->delete();
        return response()->json(['deleted' => true]);
    }

    // ─────────────────────── campos configuráveis (Fase 2) ───────────────────

    private function field(int $id): KanbanField
    {
        return KanbanField::whereHas('board', $this->boardAccessScope())->findOrFail($id);
    }

    public function storeField(Request $request, int $boardId): JsonResponse
    {
        $board = $this->board($boardId);
        $data = $this->validateField($request, true);
        $field = $board->fields()->create([
            'name'          => $data['name'],
            'type'          => $data['type'],
            'required'      => $data['required'] ?? false,
            'show_on_front' => $data['show_on_front'] ?? false,
            'options'       => $data['options'] ?? null,
            'default_value' => $data['default_value'] ?? null,
            'position'      => (int) $board->fields()->max('position') + 1,
        ]);
        return response()->json($this->presentField($field), 201);
    }

    public function updateField(Request $request, int $id): JsonResponse
    {
        $field = $this->field($id);
        $field->update($this->validateField($request, false));
        return response()->json($this->presentField($field->fresh()));
    }

    public function destroyField(int $id): JsonResponse
    {
        $this->field($id)->delete();
        return response()->json(['deleted' => true]);
    }

    public function reorderFields(Request $request, int $boardId): JsonResponse
    {
        $board = $this->board($boardId);
        $order = $request->validate(['order' => 'required|array', 'order.*' => 'integer'])['order'];
        $valid = $board->fields()->pluck('id')->all();
        foreach ($order as $pos => $fid) {
            if (in_array((int) $fid, $valid, true)) KanbanField::where('id', $fid)->update(['position' => $pos]);
        }
        return response()->json(['ok' => true]);
    }

    // ──────────────────────────────── cards ──────────────────────────────────

    public function storeCard(Request $request, int $columnId): JsonResponse
    {
        $col = $this->column($columnId);
        $data = $this->validateCard($request, $col->board_id, true);

        $card = KanbanCard::create([
            'board_id'            => $col->board_id,
            'column_id'           => $col->id,
            'title'               => $data['title'],
            'description'         => $data['description'] ?? null,
            'responsible_user_id' => $data['responsible_user_id'] ?? null,
            'start_date'          => $data['start_date'] ?? null,
            'due_date'            => $data['due_date'] ?? null,
            'priority'            => $data['priority'] ?? null,
            'created_by_user_id'  => Auth::id(),
            'position'            => (int) KanbanCard::where('column_id', $col->id)->max('position') + 1,
        ]);
        if (array_key_exists('label_ids', $data)) {
            $card->labels()->sync($this->scopeLabelIds($col->board_id, $data['label_ids'] ?? []));
        }
        if (array_key_exists('field_values', $data)) {
            $this->saveFieldValues($card, $data['field_values'] ?? []);
        }
        if (array_key_exists('member_ids', $data)) {
            $card->members()->sync($this->scopeCustomerUserIds($data['member_ids'] ?? []));
        }
        $this->logEvent($card, 'created', ['to_column_id' => $col->id]);

        return response()->json($this->cardFull($card->fresh()), 201);
    }

    public function showCard(int $id): JsonResponse
    {
        return response()->json($this->cardFull($this->card($id)));
    }

    public function updateCard(Request $request, int $id): JsonResponse
    {
        $card = $this->card($id);
        $data = $this->validateCard($request, $card->board_id, false);
        $card->fill(collect($data)->except(['label_ids', 'field_values', 'member_ids'])->all())->save();
        if (array_key_exists('label_ids', $data)) {
            $card->labels()->sync($this->scopeLabelIds($card->board_id, $data['label_ids'] ?? []));
        }
        if (array_key_exists('field_values', $data)) {
            $this->saveFieldValues($card, $data['field_values'] ?? []);
        }
        if (array_key_exists('member_ids', $data)) {
            $card->members()->sync($this->scopeCustomerUserIds($data['member_ids'] ?? []));
        }
        $this->logEvent($card, 'updated');
        return response()->json($this->cardFull($card->fresh()));
    }

    public function destroyCard(int $id): JsonResponse
    {
        $card = $this->card($id);
        $this->logEvent($card, 'deleted', ['from_column_id' => $card->column_id]);
        $card->delete();
        return response()->json(['deleted' => true]);
    }

    /** Drag-and-drop: move o card pra outra coluna e/ou posição. */
    public function moveCard(Request $request, int $id): JsonResponse
    {
        $card = $this->card($id);
        $data = $request->validate([
            'column_id' => 'required|integer',
            'position'  => 'nullable|integer|min:0',
        ]);
        $col = $this->column((int) $data['column_id']); // valida que a coluna é do mesmo cliente
        if ($col->board_id !== $card->board_id) {
            return response()->json(['message' => 'Coluna de outro quadro.'], 422);
        }

        $origCol = (int) $card->column_id;
        $newPos = $data['position'] ?? ((int) KanbanCard::where('column_id', $col->id)->max('position') + 1);
        DB::transaction(function () use ($card, $col, $newPos) {
            // Abre espaço na coluna de destino.
            KanbanCard::where('column_id', $col->id)->where('position', '>=', $newPos)->increment('position');
            $card->update(['column_id' => $col->id, 'position' => $newPos]);
        });
        if ($origCol !== (int) $col->id) {
            $this->logEvent($card, 'moved', ['from_column_id' => $origCol, 'to_column_id' => $col->id]);
        }

        return response()->json(['ok' => true]);
    }

    // ────────────────────────────── checklist ────────────────────────────────

    public function storeChecklistItem(Request $request, int $cardId): JsonResponse
    {
        $card = $this->card($cardId);
        $data = $request->validate(['text' => 'required|string|max:300']);
        $item = $card->checklistItems()->create([
            'text' => $data['text'], 'is_done' => false,
            'position' => (int) $card->checklistItems()->max('position') + 1,
        ]);
        return response()->json($item, 201);
    }

    public function updateChecklistItem(Request $request, int $id): JsonResponse
    {
        $item = KanbanChecklistItem::whereHas('card.board', $this->boardAccessScope())->findOrFail($id);
        $item->update($request->validate([
            'text'    => 'sometimes|required|string|max:300',
            'is_done' => 'sometimes|boolean',
        ]));
        return response()->json($item->fresh());
    }

    public function destroyChecklistItem(int $id): JsonResponse
    {
        KanbanChecklistItem::whereHas('card.board', $this->boardAccessScope())->findOrFail($id)->delete();
        return response()->json(['deleted' => true]);
    }

    // ─────────────────────────────── comments ────────────────────────────────

    public function storeComment(Request $request, int $cardId): JsonResponse
    {
        $card = $this->card($cardId);
        $data = $request->validate(['body' => 'required|string|max:5000']);
        $comment = $card->comments()->create(['user_id' => Auth::id(), 'body' => $data['body']]);
        $comment->load('user:id,name');
        $this->logEvent($card, 'comment');
        return response()->json($this->presentComment($comment), 201);
    }

    public function destroyComment(int $id): JsonResponse
    {
        $comment = KanbanCardComment::whereHas('card.board', $this->boardAccessScope())->findOrFail($id);
        abort_unless((int) $comment->user_id === (int) Auth::id() || Auth::user()->isAdmin(), 403, 'Sem permissão.');
        $comment->delete();
        return response()->json(['deleted' => true]);
    }

    // ──────────────────── auditoria / histórico (Fase 4) ─────────────────────

    private function logEvent(KanbanCard $card, string $type, array $extra = []): void
    {
        KanbanCardEvent::create(array_merge([
            'board_id'   => $card->board_id,
            'card_id'    => $card->id,
            'user_id'    => Auth::id(),
            'type'       => $type,
            'card_title' => $card->title,
            'created_at' => now(),
        ], $extra));
    }

    /** Histórico de um card. */
    public function cardHistory(int $id): JsonResponse
    {
        $card = $this->card($id);
        $events = KanbanCardEvent::where('card_id', $card->id)
            ->with('user:id,name')->orderByDesc('created_at')->orderByDesc('id')->limit(100)->get()
            ->map(fn ($e) => $this->presentEvent($e));
        return response()->json(['items' => $events]);
    }

    private function presentEvent(KanbanCardEvent $e): array
    {
        return [
            'id' => $e->id, 'type' => $e->type,
            'card_title' => $e->card_title,
            'from_column_id' => $e->from_column_id, 'to_column_id' => $e->to_column_id,
            'meta' => $e->meta,
            'at' => optional($e->created_at)->toIso8601String(),
            'user' => $e->user ? ['id' => $e->user->id, 'name' => $e->user->name] : null,
        ];
    }

    // ──────────────────── membros do quadro / participantes ──────────────────

    /** Lista membros do quadro (vazio = todos os usuários do cliente têm acesso). */
    public function boardMembers(int $boardId): JsonResponse
    {
        $board = $this->board($boardId);
        $ids = $board->members()->pluck('users.id')->all();
        return response()->json(['user_ids' => $ids]);
    }

    /** Define os membros do quadro (lista de user_ids do próprio cliente). */
    public function setBoardMembers(Request $request, int $boardId): JsonResponse
    {
        $board = $this->board($boardId);
        $ids = $request->validate(['user_ids' => 'array', 'user_ids.*' => 'integer'])['user_ids'] ?? [];
        // Só usuários REAIS do cliente (mesmo filtro do responsável).
        $valid = User::where('customer_id', $this->customerId())->where('type', 'cliente')->where('enabled', true)
            ->whereIn('id', $ids)->pluck('id')->all();
        $board->members()->sync($valid);
        return response()->json(['user_ids' => $valid]);
    }

    // ─────────────────────────────── relatório ───────────────────────────────

    public function report(int $boardId): JsonResponse
    {
        $board = $this->board($boardId);
        $board->load(['columns', 'columns.cards' => fn ($q) => $q->with('responsible:id,name')]);
        $today = now()->startOfDay();

        $byColumn = [];
        $byResponsible = [];
        $total = 0; $overdue = 0; $doneCount = 0;
        // Considera a ÚLTIMA coluna (maior position) como "concluído" pra métricas simples.
        $lastColId = $board->columns->sortByDesc('position')->first()?->id;

        foreach ($board->columns as $col) {
            $cards = $col->cards;
            $byColumn[] = ['column_id' => $col->id, 'name' => $col->name, 'color' => $col->color, 'count' => $cards->count()];
            foreach ($cards as $card) {
                $total++;
                if ($col->id === $lastColId) $doneCount++;
                if ($col->id !== $lastColId && $card->due_date && $card->due_date->lt($today)) $overdue++;
                if ($card->responsible) {
                    $rid = $card->responsible->id;
                    $byResponsible[$rid] = $byResponsible[$rid] ?? ['user_id' => $rid, 'name' => $card->responsible->name, 'count' => 0];
                    $byResponsible[$rid]['count']++;
                }
            }
        }

        // Tempo médio por coluna (dias) a partir dos eventos de movimentação.
        $avgDays = $this->avgTimePerColumn($board->id);

        return response()->json([
            'totals' => ['cards' => $total, 'done' => $doneCount, 'overdue' => $overdue, 'open' => $total - $doneCount],
            'by_column' => $byColumn,
            'by_responsible' => array_values(collect($byResponsible)->sortByDesc('count')->all()),
            'avg_days_per_column' => $avgDays,
        ]);
    }

    /** Tempo médio (dias) que os cards passaram em cada coluna, via eventos created/moved. */
    private function avgTimePerColumn(int $boardId): array
    {
        $events = KanbanCardEvent::where('board_id', $boardId)
            ->whereIn('type', ['created', 'moved'])
            ->orderBy('card_id')->orderBy('created_at')->orderBy('id')
            ->get(['card_id', 'type', 'from_column_id', 'to_column_id', 'created_at']);

        $sum = []; $cnt = [];  // column_id => total seconds / n
        $byCard = $events->groupBy('card_id');
        foreach ($byCard as $evs) {
            $prevCol = null; $prevAt = null;
            foreach ($evs as $e) {
                $col = $e->type === 'created' ? $e->to_column_id : $e->from_column_id;
                if ($prevCol !== null && $prevAt !== null && $e->created_at) {
                    $secs = $e->created_at->getTimestamp() - $prevAt->getTimestamp();
                    if ($secs > 0) { $sum[$prevCol] = ($sum[$prevCol] ?? 0) + $secs; $cnt[$prevCol] = ($cnt[$prevCol] ?? 0) + 1; }
                }
                $prevCol = $e->type === 'created' ? $e->to_column_id : $e->to_column_id;
                $prevAt = $e->created_at;
            }
        }
        $out = [];
        foreach ($sum as $colId => $s) {
            $out[] = ['column_id' => (int) $colId, 'avg_days' => round($s / max(1, $cnt[$colId]) / 86400, 1)];
        }
        return $out;
    }

    // ─────────────────────────── usuários atribuíveis ────────────────────────

    public function assignableUsers(): JsonResponse
    {
        // Só usuários REAIS do cliente logado — mesmo filtro do "Ver como" (impersonation):
        // type=cliente + enabled=true + customer_id. Sem isso apareciam ~94 registros
        // importados/desabilitados (ex.: Competências) que não são logins do cliente.
        $users = User::where('customer_id', $this->customerId())
            ->where('type', 'cliente')
            ->where('enabled', true)
            ->orderBy('name')->get(['id', 'name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]);
        return response()->json(['items' => $users]);
    }

    // ─────────────────────────────── internos ────────────────────────────────

    private function validateCard(Request $request, int $boardId, bool $creating): array
    {
        return $request->validate([
            'title'               => ($creating ? 'required' : 'sometimes|required') . '|string|max:200',
            'description'         => 'nullable|string|max:20000',
            'responsible_user_id' => 'nullable|integer',
            'start_date'          => 'nullable|date',
            'due_date'            => 'nullable|date',
            'priority'            => 'nullable|in:' . implode(',', self::PRIORITIES),
            'label_ids'           => 'nullable|array',
            'label_ids.*'         => 'integer',
            'field_values'        => 'nullable|array',
            'member_ids'          => 'nullable|array',
            'member_ids.*'        => 'integer',
        ]);
    }

    /** Garante que os labels pertencem ao quadro (segurança). */
    private function scopeLabelIds(int $boardId, array $ids): array
    {
        if (empty($ids)) return [];
        return KanbanLabel::where('board_id', $boardId)->whereIn('id', $ids)->pluck('id')->all();
    }

    /** Só usuários reais do cliente logado (participantes/membros). */
    private function scopeCustomerUserIds(array $ids): array
    {
        if (empty($ids)) return [];
        return User::where('customer_id', $this->customerId())->where('type', 'cliente')->where('enabled', true)
            ->whereIn('id', $ids)->pluck('id')->all();
    }

    private function validateField(Request $request, bool $creating): array
    {
        return $request->validate([
            'name'          => ($creating ? 'required' : 'sometimes|required') . '|string|max:80',
            'type'          => ($creating ? 'required' : 'sometimes|required') . '|in:' . implode(',', KanbanField::TYPES),
            'required'      => 'sometimes|boolean',
            'show_on_front' => 'sometimes|boolean',
            'options'       => 'nullable|array',
            'options.*'     => 'string|max:120',
            'default_value' => 'nullable|string|max:500',
        ]);
    }

    private function presentField(KanbanField $f): array
    {
        return [
            'id' => $f->id, 'name' => $f->name, 'type' => $f->type,
            'required' => (bool) $f->required, 'show_on_front' => (bool) $f->show_on_front,
            'options' => $f->options ?? [], 'default_value' => $f->default_value, 'position' => $f->position,
        ];
    }

    /** Grava os valores dos campos do card. value=array (multiselect) vira JSON; vazio remove. */
    private function saveFieldValues(KanbanCard $card, array $values): void
    {
        $valid = KanbanField::where('board_id', $card->board_id)->pluck('id')->all();
        foreach ($values as $fieldId => $val) {
            $fieldId = (int) $fieldId;
            if (!in_array($fieldId, $valid, true)) continue;
            $stored = is_array($val) ? json_encode(array_values($val)) : (($val === null || $val === '') ? null : (string) $val);
            if ($stored === null) {
                KanbanCardFieldValue::where('card_id', $card->id)->where('field_id', $fieldId)->delete();
            } else {
                KanbanCardFieldValue::updateOrCreate(['card_id' => $card->id, 'field_id' => $fieldId], ['value' => $stored]);
            }
        }
    }

    /** Mapa field_id => valor bruto (string; multiselect fica JSON — o front decodifica pelo tipo). */
    private function fieldValuesMap(KanbanCard $card): array
    {
        $vals = $card->relationLoaded('fieldValues') ? $card->fieldValues : $card->fieldValues()->get();
        $out = [];
        foreach ($vals as $v) $out[(string) $v->field_id] = $v->value;
        return $out;
    }

    private function boardFull(KanbanBoard $board): array
    {
        $board->load([
            'columns.cards' => fn ($q) => $q->with(['responsible:id,name', 'labels', 'checklistItems', 'fieldValues'])->withCount('comments'),
            'labels', 'fields',
        ]);
        return [
            'id' => $board->id, 'name' => $board->name, 'description' => $board->description,
            'color' => $board->color,
            'labels' => $board->labels->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'color' => $l->color])->values(),
            'fields' => $board->fields->map(fn (KanbanField $f) => $this->presentField($f))->values(),
            'columns' => $board->columns->map(fn (KanbanColumn $c) => [
                'id' => $c->id, 'name' => $c->name, 'color' => $c->color, 'position' => $c->position,
                'cards' => $c->cards->map(fn (KanbanCard $card) => $this->cardSummary($card))->values(),
            ])->values(),
        ];
    }

    private function cardSummary(KanbanCard $card): array
    {
        $checklist = $card->relationLoaded('checklistItems') ? $card->checklistItems : collect();
        return [
            'id' => $card->id, 'column_id' => $card->column_id, 'title' => $card->title,
            'description' => $card->description ? true : false, // só sinaliza que tem descrição
            'priority' => $card->priority,
            'start_date' => $card->start_date?->toDateString(),
            'due_date' => $card->due_date?->toDateString(),
            'position' => $card->position,
            'responsible' => $card->responsible ? ['id' => $card->responsible->id, 'name' => $card->responsible->name] : null,
            'labels' => $card->labels->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'color' => $l->color])->values(),
            'checklist_total' => $checklist->count(),
            'checklist_done' => $checklist->where('is_done', true)->count(),
            'comments_count' => $card->comments_count ?? 0,
            'field_values' => $this->fieldValuesMap($card),
        ];
    }

    private function cardFull(KanbanCard $card): array
    {
        $card->load(['responsible:id,name', 'labels', 'checklistItems', 'comments.user:id,name', 'attachments', 'fieldValues', 'members:id,name']);
        return array_merge($this->cardSummary($card), [
            'description' => $card->description,
            'members' => $card->members->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values(),
            'checklist' => $card->checklistItems->map(fn ($i) => ['id' => $i->id, 'text' => $i->text, 'is_done' => (bool) $i->is_done])->values(),
            'comments' => $card->comments->map(fn ($c) => $this->presentComment($c))->values(),
            'attachments' => $card->attachments->map(fn ($a) => ['id' => $a->id, 'name' => $a->original_name, 'mime' => $a->mime_type])->values(),
        ]);
    }

    private function presentComment(KanbanCardComment $c): array
    {
        return [
            'id' => $c->id, 'body' => $c->body,
            'created_at' => optional($c->created_at)->toIso8601String(),
            'user' => $c->user ? ['id' => $c->user->id, 'name' => $c->user->name] : null,
        ];
    }
}
