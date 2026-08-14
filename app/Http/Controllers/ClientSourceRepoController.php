<?php

namespace App\Http\Controllers;

use App\Models\ClientSourceRepo;
use App\Models\Customer;
use App\Services\PermissionService;
use App\SourceCode\Exceptions\SourceIntegrationException;
use App\SourceCode\GitHubSourceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fase 0 — cadastro das FONTES GIT autorizadas por cliente ("Repositórios de Código-Fonte").
 * Restrito a quem tem 'source_code.manage'. "Remover" = desativar (nunca exclui fisicamente).
 * READ-ONLY no GitHub (só "Testar acesso").
 */
class ClientSourceRepoController extends Controller
{
    private function authorizeManage(Request $request): void
    {
        $perms = PermissionService::for($request->user());
        abort_unless(in_array('*', $perms, true) || in_array('source_code.manage', $perms, true), 403, 'Sem permissão para gerenciar fontes de código.');
    }

    public function index(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeManage($request);
        $rows = $customer->sourceRepos()->with(['creator:id,name', 'updater:id,name'])
            ->orderByDesc('active')->orderBy('tipo')->orderBy('repository')->get();
        return response()->json(['data' => $rows->map(fn ($r) => $this->serialize($r))]);
    }

    public function store(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeManage($request);
        $v = $this->validated($request);
        $v['customer_id'] = $customer->id;
        $v['created_by'] = $request->user()->id;
        $v['updated_by'] = $request->user()->id;
        $repo = ClientSourceRepo::create($v);
        return response()->json(['data' => $this->serialize($repo->fresh(['creator:id,name', 'updater:id,name']))], 201);
    }

    public function update(Request $request, ClientSourceRepo $sourceRepo): JsonResponse
    {
        $this->authorizeManage($request);
        $v = $this->validated($request, false);
        $v['updated_by'] = $request->user()->id;
        $v['needs_review'] = false; // admin editou/confirmou o repo → deixa de estar pendente
        $sourceRepo->update($v);
        return response()->json(['data' => $this->serialize($sourceRepo->fresh(['creator:id,name', 'updater:id,name']))]);
    }

    /** Confirma um vínculo pendente de verificação (auto-provisionado sobre repo pré-existente). */
    public function verify(Request $request, ClientSourceRepo $sourceRepo): JsonResponse
    {
        $this->authorizeManage($request);
        $sourceRepo->update(['needs_review' => false, 'updated_by' => $request->user()->id]);
        return response()->json(['data' => $this->serialize($sourceRepo->fresh(['creator:id,name', 'updater:id,name']))]);
    }

    /** "Remover" = DESATIVAR (preserva rastreabilidade). Não exclui fisicamente. */
    public function destroy(Request $request, ClientSourceRepo $sourceRepo): JsonResponse
    {
        $this->authorizeManage($request);
        $sourceRepo->update(['active' => false, 'updated_by' => $request->user()->id]);
        return response()->json(['data' => $this->serialize($sourceRepo->fresh(['creator:id,name', 'updater:id,name']))]);
    }

    /** Testa o acesso read-only ao repo/branch/base_path e devolve algo operacionalmente útil. */
    public function test(Request $request, ClientSourceRepo $sourceRepo, GitHubSourceService $svc): JsonResponse
    {
        $this->authorizeManage($request);
        try {
            $info = $svc->testAccess($sourceRepo);
        } catch (SourceIntegrationException $e) {
            return response()->json(['ok' => false, 'code' => $e->errorCode, 'message' => $e->getMessage()]);
        }

        $msgs = ['✓ Conectado', 'GitHub App: instalada', "Org: {$sourceRepo->owner}", "Repo: {$sourceRepo->repository}"];
        if ($info['branch_found']) {
            $msgs[] = "Branch: {$sourceRepo->branch}";
            $msgs[] = 'Base: ' . ($sourceRepo->base_path ?: '/');
            $msgs[] = 'Arquivos: ' . number_format($info['file_count'], 0, ',', '.');
            if (!$info['base_path_found'] && $sourceRepo->normalizedBasePath() !== '') {
                $msgs[] = "⚠ base_path \"{$sourceRepo->base_path}\" não encontrado (0 arquivos)";
            }
        } else {
            $suggest = $info['default_branch'] ? " (padrão do repo: \"{$info['default_branch']}\")" : '';
            $msgs[] = "⚠ branch \"{$sourceRepo->branch}\" NÃO existe{$suggest}";
        }
        $msgs[] = 'Verificado em ' . now()->format('d/m/Y H:i');

        $ok = $info['branch_found'] && ($info['base_path_found'] || $sourceRepo->normalizedBasePath() === '');
        return response()->json([
            'ok'         => $ok,
            'app'        => 'instalada',
            'owner'      => $sourceRepo->owner,
            'repository' => $sourceRepo->repository,
            'branch'     => $sourceRepo->branch,
            'base_path'  => $sourceRepo->base_path,
            'file_count' => $info['file_count'],
            'checked_at' => now()->toIso8601String(),
            'info'       => $info,
            'message'    => implode(' · ', $msgs),
        ]);
    }

    /** Lista os repositórios que a App enxerga num owner (alimenta o seletor). Degrada p/ vazio. */
    public function available(Request $request, GitHubSourceService $svc): JsonResponse
    {
        $this->authorizeManage($request);
        $owner = trim((string) $request->query('owner', ''));
        if ($owner === '') {
            return response()->json(['ok' => false, 'repos' => []]);
        }
        try {
            return response()->json(['ok' => true, 'repos' => $svc->availableRepos($owner)]);
        } catch (SourceIntegrationException $e) {
            return response()->json(['ok' => false, 'code' => $e->errorCode, 'message' => $e->getMessage(), 'repos' => []]);
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function validated(Request $request, bool $creating = true): array
    {
        $data = $request->validate([
            'owner'      => ($creating ? 'required' : 'sometimes') . '|string|max:120',
            'repository' => ($creating ? 'required' : 'sometimes') . '|string|max:140',
            'branch'     => ($creating ? 'required' : 'sometimes') . '|string|max:140',
            'base_path'  => 'nullable|string|max:400',
            'tipo'       => 'nullable|in:' . implode(',', ClientSourceRepo::TIPOS),
            'descricao'  => 'nullable|string|max:200',
            'active'     => 'boolean',
        ]);
        // base_path é NOT NULL default '' — o middleware ConvertEmptyStringsToNull transforma
        // '' em null; sem este coalesce, o INSERT manda null explícito e viola a constraint.
        // Normaliza sem barras nas pontas ('' = raiz do repo).
        if (array_key_exists('base_path', $data)) {
            $data['base_path'] = trim((string) $data['base_path'], '/');
        }
        return $data;
    }

    private function serialize(ClientSourceRepo $r): array
    {
        return [
            'id'           => $r->id,
            'owner'        => $r->owner,
            'repository'   => $r->repository,
            'full_name'    => $r->fullName(),
            'branch'       => $r->branch,
            'base_path'    => $r->base_path,
            'tipo'         => $r->tipo,
            'descricao'    => $r->descricao,
            'active'       => $r->active,
            'needs_review' => (bool) $r->needs_review,
            'created_by'   => $r->creator?->name,
            'updated_by'   => $r->updater?->name,
            'updated_at'   => $r->updated_at?->toIso8601String(),
        ];
    }
}
