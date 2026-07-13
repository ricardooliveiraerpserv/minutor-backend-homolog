<?php

namespace App\Http\Controllers;

use App\Models\Adiantamento;
use App\Models\Partner;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Rotina de Adiantamento — adiantamentos a consultores e parceiros, parcelados
 * por competência. Cada parcela é descontada no Fechamento (consultor/parceiro)
 * do respectivo mês (somada ao campo `adiantamento` do ajuste manual).
 */
class AdiantamentoController extends Controller
{
    private function authorizeManage(Request $request): bool
    {
        $u = $request->user();
        return $u && ($u->isAdmin() || $u->type === 'administrativo');
    }

    /** Lista os adiantamentos (com parcelas + nome do beneficiário). */
    public function index(Request $request): JsonResponse
    {
        $q = Adiantamento::with(['parcelas', 'createdByUser:id,name']);

        if ($tipo = $request->query('tipo')) {
            $q->where('beneficiario_tipo', $tipo);
        }
        if ($bid = $request->query('beneficiario_id')) {
            $q->where('beneficiario_id', (int) $bid);
        }

        // Resolve nomes em lote (evita N+1 no beneficiarioNome()).
        $itens = $q->orderByDesc('id')->get();
        $userIds    = $itens->where('beneficiario_tipo', Adiantamento::TIPO_CONSULTOR)->pluck('beneficiario_id')->all();
        $partnerIds = $itens->where('beneficiario_tipo', Adiantamento::TIPO_PARCEIRO)->pluck('beneficiario_id')->all();
        $users    = User::whereIn('id', $userIds)->pluck('name', 'id');
        $partners = Partner::whereIn('id', $partnerIds)->pluck('name', 'id');

        $data = $itens->map(fn ($a) => [
            'id'                   => $a->id,
            'beneficiario_tipo'    => $a->beneficiario_tipo,
            'beneficiario_id'      => $a->beneficiario_id,
            'beneficiario_nome'    => $a->beneficiario_tipo === Adiantamento::TIPO_PARCEIRO
                ? ($partners[$a->beneficiario_id] ?? '—')
                : ($users[$a->beneficiario_id] ?? '—'),
            'tipo'                 => $a->tipo ?? Adiantamento::NATUREZA_ADIANTAMENTO,
            'valor_total'          => (float) $a->valor_total,
            'data_realizado'       => $a->data_realizado?->format('Y-m-d'),
            'disponibilizado'      => (bool) $a->disponibilizado,
            'num_parcelas'         => (int) $a->num_parcelas,
            'primeira_competencia' => $a->primeira_competencia,
            'descricao'            => $a->descricao,
            'criado_por'           => $a->createdByUser?->name,
            'created_at'           => $a->created_at?->toISOString(),
            'parcelas'             => $a->parcelas->map(fn ($p) => [
                'numero'     => $p->numero,
                'year_month' => $p->year_month,
                'valor'      => (float) $p->valor,
            ])->values(),
        ]);

        return response()->json(['data' => $data]);
    }

    /** Beneficiários disponíveis para o formulário (colaboradores + parceiros). */
    public function beneficiarios(): JsonResponse
    {
        // Colaboradores = consultores + diretores + coordenadores (todos User).
        $consultores = User::where('enabled', true)
            ->whereNotIn('type', ['parceiro_admin', 'cliente'])
            ->where(function ($q) {
                $q->whereNotNull('consultant_type')
                  ->orWhere('is_diretor', true)
                  ->orWhere('type', 'coordenador');
            })
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($u) => ['id' => $u->id, 'nome' => $u->name]);

        $parceiros = Partner::whereRaw('"active" = true')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($p) => ['id' => $p->id, 'nome' => $p->name]);

        return response()->json([
            'consultores' => $consultores,
            'parceiros'   => $parceiros,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (!$this->authorizeManage($request)) {
            return response()->json(['message' => 'Sem permissão para gerenciar adiantamentos.'], 403);
        }

        $validated = $this->validatePayload($request);
        if (!$this->beneficiarioExiste($validated['beneficiario_tipo'], (int) $validated['beneficiario_id'])) {
            return response()->json(['message' => 'Beneficiário não encontrado.'], 422);
        }

        $adiantamento = DB::transaction(function () use ($validated, $request) {
            $tipo = $validated['tipo'] ?? Adiantamento::NATUREZA_ADIANTAMENTO;
            $a = Adiantamento::create([
                'beneficiario_tipo'    => $validated['beneficiario_tipo'],
                'beneficiario_id'      => (int) $validated['beneficiario_id'],
                'tipo'                 => $tipo,
                'valor_total'          => round((float) $validated['valor_total'], 2),
                'data_realizado'       => $validated['data_realizado'],
                // Adiantamento é sempre entregue; empréstimo respeita o flag (não conta enquanto false).
                'disponibilizado'      => $tipo === Adiantamento::NATUREZA_EMPRESTIMO
                    ? (bool) ($validated['disponibilizado'] ?? false)
                    : true,
                'num_parcelas'         => (int) $validated['num_parcelas'],
                'primeira_competencia' => $validated['primeira_competencia'],
                'descricao'            => $validated['descricao'] ?? null,
                'created_by'           => $request->user()->id,
            ]);
            $this->syncParcelas($a, $validated);
            return $a;
        });

        return response()->json([
            'message'      => 'Adiantamento criado.',
            'adiantamento' => $adiantamento->load('parcelas'),
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if (!$this->authorizeManage($request)) {
            return response()->json(['message' => 'Sem permissão para gerenciar adiantamentos.'], 403);
        }

        $adiantamento = Adiantamento::findOrFail($id);
        $validated = $this->validatePayload($request);
        if (!$this->beneficiarioExiste($validated['beneficiario_tipo'], (int) $validated['beneficiario_id'])) {
            return response()->json(['message' => 'Beneficiário não encontrado.'], 422);
        }

        DB::transaction(function () use ($adiantamento, $validated) {
            $tipo = $validated['tipo'] ?? Adiantamento::NATUREZA_ADIANTAMENTO;
            $adiantamento->update([
                'beneficiario_tipo'    => $validated['beneficiario_tipo'],
                'beneficiario_id'      => (int) $validated['beneficiario_id'],
                'tipo'                 => $tipo,
                'valor_total'          => round((float) $validated['valor_total'], 2),
                'data_realizado'       => $validated['data_realizado'],
                'disponibilizado'      => $tipo === Adiantamento::NATUREZA_EMPRESTIMO
                    ? (bool) ($validated['disponibilizado'] ?? false)
                    : true,
                'num_parcelas'         => (int) $validated['num_parcelas'],
                'primeira_competencia' => $validated['primeira_competencia'],
                'descricao'            => $validated['descricao'] ?? null,
            ]);
            $adiantamento->parcelas()->delete();
            $this->syncParcelas($adiantamento, $validated);
        });

        return response()->json([
            'message'      => 'Adiantamento atualizado.',
            'adiantamento' => $adiantamento->fresh('parcelas'),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        if (!$this->authorizeManage($request)) {
            return response()->json(['message' => 'Sem permissão para gerenciar adiantamentos.'], 403);
        }

        $adiantamento = Adiantamento::findOrFail($id);
        $adiantamento->delete(); // cascade nas parcelas

        return response()->json(['message' => 'Adiantamento excluído.']);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'beneficiario_tipo'    => ['required', 'in:consultor,parceiro'],
            'beneficiario_id'      => ['required', 'integer'],
            'tipo'                 => ['nullable', 'in:adiantamento,emprestimo'],
            'valor_total'          => ['required', 'numeric', 'min:0.01'],
            'data_realizado'       => ['required', 'date'],
            'disponibilizado'      => ['nullable', 'boolean'],
            'num_parcelas'         => ['required', 'integer', 'min:1', 'max:120'],
            'primeira_competencia' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'descricao'            => ['nullable', 'string', 'max:1000'],
            // Parcelas editadas manualmente (opcional). Se ausente, geramos automático.
            'parcelas'              => ['nullable', 'array'],
            'parcelas.*.year_month' => ['required_with:parcelas', 'regex:/^\d{4}-\d{2}$/'],
            'parcelas.*.valor'      => ['required_with:parcelas', 'numeric', 'min:0'],
        ]);
    }

    private function beneficiarioExiste(string $tipo, int $id): bool
    {
        return $tipo === Adiantamento::TIPO_PARCEIRO
            ? Partner::whereKey($id)->exists()
            : User::whereKey($id)->exists();
    }

    /**
     * Cria as parcelas: usa o array enviado (edição manual) ou gera mensais
     * consecutivas a partir da competência inicial (valor_total ÷ nº parcelas,
     * com a última absorvendo o arredondamento).
     *
     * @param array<string, mixed> $validated
     */
    private function syncParcelas(Adiantamento $a, array $validated): void
    {
        if (!empty($validated['parcelas'])) {
            foreach (array_values($validated['parcelas']) as $i => $p) {
                $a->parcelas()->create([
                    'numero'     => $i + 1,
                    'year_month' => $p['year_month'],
                    'valor'      => round((float) $p['valor'], 2),
                ]);
            }
            return;
        }

        $n     = (int) $validated['num_parcelas'];
        $total = round((float) $validated['valor_total'], 2);
        $base  = floor(($total / $n) * 100) / 100;       // valor por parcela (trunca centavos)
        $resto = round($total - $base * $n, 2);          // sobra vai na última
        $comp  = Carbon::createFromFormat('Y-m', $validated['primeira_competencia'])->startOfMonth();

        for ($i = 1; $i <= $n; $i++) {
            $valor = $i === $n ? round($base + $resto, 2) : $base;
            $a->parcelas()->create([
                'numero'     => $i,
                'year_month' => $comp->format('Y-m'),
                'valor'      => $valor,
            ]);
            $comp->addMonth();
        }
    }
}
