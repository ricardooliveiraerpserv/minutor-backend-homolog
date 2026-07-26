<?php

namespace App\Http\Controllers;

use App\Models\BotProactiveDetector;
use App\Services\Bot\ProactiveAlertsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD dos detectores proativos do BOT (configuráveis pelo admin).
 * Endpoints sob /api/v1/bot/detectors/*.
 */
class BotDetectorController extends Controller
{
    public function __construct(private readonly ProactiveAlertsService $alerts)
    {
    }

    /**
     * A3 (segurança): detectores executam SQL escrito à mão (SELECT livre) direto no banco.
     * Mesmo bloqueando escrita, um SELECT lê QUALQUER tabela (senhas hash, tokens, dados de
     * todos os clientes). É recurso de admin — trava aqui pra não bastar estar logado.
     */
    private function ensureAdmin(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403, 'Apenas administradores podem gerenciar detectores.');
    }

    public function index(): JsonResponse
    {
        $this->ensureAdmin();
        $items = BotProactiveDetector::query()->orderBy('id')->get();
        return response()->json([
            'data'         => $items,
            'types'        => BotProactiveDetector::TYPES,
            'severities'   => ['info', 'low', 'medium', 'high', 'critical'],
            'event_types'  => array_map(fn ($c) => $c->value, \App\Enums\FeedEventType::cases()),
            'sources'      => array_map(fn ($c) => $c->value, \App\Enums\FeedSource::cases()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $data = $this->validatePayload($request, isUpdate: false);
        BotProactiveDetector::create(array_merge([
            'active' => true,
        ], $data));
        return $this->index();
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->ensureAdmin();
        $d = BotProactiveDetector::findOrFail($id);
        $data = $this->validatePayload($request, isUpdate: true);
        // Slug não muda em sistema
        if ($d->is_system && isset($data['slug']) && $data['slug'] !== $d->slug) {
            return response()->json([
                'message' => 'Detectores de sistema não podem trocar de slug.',
            ], 422);
        }
        $d->update($data);
        return $this->index();
    }

    public function destroy(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $d = BotProactiveDetector::findOrFail($id);
        if ($d->is_system) {
            return response()->json([
                'message' => 'Detectores de sistema não podem ser excluídos. Desative em vez de excluir.',
            ], 422);
        }
        $d->delete();
        return $this->index();
    }

    /** Roda o detector em modo dry e retorna o que ele criaria. */
    public function test(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $d = BotProactiveDetector::findOrFail($id);
        [$count, $err] = $this->alerts->runDetector($d, dry: true);
        return response()->json([
            'detector_id' => $d->id,
            'slug'        => $d->slug,
            'would_create' => $count,
            'error'        => $err,
        ], $err ? 422 : 200);
    }

    /** Executa todos os detectores ativos (ou um específico) — útil pra disparar manualmente. */
    public function run(Request $request, ?int $id = null): JsonResponse
    {
        $this->ensureAdmin();
        $dry = (bool) $request->input('dry', false);

        if ($id) {
            $d = BotProactiveDetector::findOrFail($id);
            [$count, $err] = $this->alerts->runDetector($d, $dry);
            return response()->json([
                'detector_id' => $d->id,
                'slug'        => $d->slug,
                'alerts'      => $count,
                'error'       => $err,
            ], $err ? 422 : 200);
        }

        $total = $this->alerts->runAll($dry);
        return response()->json(['alerts' => $total]);
    }

    public function validateSql(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $sql = (string) $request->input('sql', '');
        $err = $this->alerts->validateSql($sql);
        return response()->json([
            'valid' => $err === null,
            'error' => $err,
        ]);
    }

    private function validatePayload(Request $request, bool $isUpdate): array
    {
        $req = $isUpdate ? 'sometimes' : 'required';
        $allTypes = implode(',', BotProactiveDetector::TYPES);
        $allEvents = implode(',', array_map(fn ($c) => $c->value, \App\Enums\FeedEventType::cases()));
        $allSources = implode(',', array_map(fn ($c) => $c->value, \App\Enums\FeedSource::cases()));

        $rules = [
            'slug'                => $isUpdate
                ? 'sometimes|string|max:80|alpha_dash'
                : 'required|string|max:80|alpha_dash|unique:bot_proactive_detectors,slug',
            'name'                => "$req|string|max:160",
            'description'         => 'sometimes|nullable|string|max:2000',
            'active'              => 'sometimes|boolean',
            'detector_type'       => "$req|string|in:$allTypes",
            'config'              => 'sometimes|array',
            'severity'            => 'sometimes|in:info,low,medium,high,critical',
            'source'              => "sometimes|in:$allSources",
            'event_type'          => "sometimes|in:$allEvents",
            'dedupe_window_hours' => 'sometimes|integer|min:1|max:720',
        ];
        $data = $request->validate($rules);

        // Validação extra para SQL custom
        if (($data['detector_type'] ?? null) === 'sql' || isset($data['config']['sql'])) {
            $sql = $data['config']['sql'] ?? null;
            if (is_string($sql) && $sql !== '') {
                $err = $this->alerts->validateSql($sql);
                if ($err) {
                    abort(response()->json(['message' => $err], 422));
                }
            }
        }
        return $data;
    }
}
