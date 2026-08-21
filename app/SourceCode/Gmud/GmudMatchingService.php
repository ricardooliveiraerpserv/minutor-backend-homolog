<?php

namespace App\SourceCode\Gmud;

use App\Models\ClientSourceRepo;
use App\Models\GmudPackage;
use App\Models\GmudPackageFile;
use App\Models\SourceDoc;
use App\SourceCode\Exceptions\SourceIntegrationException;
use App\SourceCode\GithubAppAuth;
use Illuminate\Support\Facades\Log;

/**
 * GMUD G2 — matching DETERMINÍSTICO entre os fontes do pacote e o Git/acervo do cliente.
 *
 * Sem IA. Compara por basename + git blob sha contra a árvore Git AO VIVO (treeBlobShas) dos repos
 * ativos do cliente. Classifica cada fonte em: new | identical | existing | ambiguous. Ambiguidade
 * (múltiplas ocorrências do mesmo nome, ou divergência só de caixa) NUNCA é resolvida sozinha —
 * vira `ambiguous` com os candidatos registrados para decisão humana. Não escolhe destino, não
 * publica, não commita.
 */
class GmudMatchingService
{
    public function __construct(private GithubAppAuth $auth)
    {
    }

    /** Casa todos os arquivos `is_source` do pacote e grava match_status/evidência em cada linha. */
    public function matchPackage(GmudPackage $package): void
    {
        $repos = $package->customer_id
            ? ClientSourceRepo::where('customer_id', $package->customer_id)->where('active', true)->get()
            : collect();

        // Índice basename → ocorrências no Git (uma leitura de árvore por repo ativo).
        [$byBasename, $byBasenameLower, $reposRead] = $this->buildGitIndex($repos);

        $files = $package->files()->where('is_source', true)->get();
        foreach ($files as $file) {
            $this->matchOne($file, $byBasename, $byBasenameLower, $repos, $reposRead);
        }
    }

    /**
     * @return array{0: array<string,array<int,array<string,mixed>>>, 1: array<string,array<int,array<string,mixed>>>, 2: array<int,int>}
     *   [exato, case-insensitive, ids dos repos lidos com sucesso]
     */
    private function buildGitIndex($repos): array
    {
        $byBasename = [];
        $byBasenameLower = [];
        $reposRead = [];
        foreach ($repos as $repo) {
            $branch = $repo->branch ?: 'main';
            try {
                $tree = $this->auth->treeBlobShas($repo->owner, $repo->repository, $branch); // [path => blob sha]
            } catch (SourceIntegrationException $e) {
                Log::warning('gmud_match.tree_unavailable', ['repo' => $repo->id, 'error' => $e->getMessage()]);
                continue;
            }
            $reposRead[] = $repo->id;
            foreach ($tree as $path => $blobSha) {
                $bn = basename($path);
                $occ = [
                    'source_repo_id' => $repo->id,
                    'owner'          => $repo->owner,
                    'repository'     => $repo->repository,
                    'branch'         => $branch,
                    'path'           => $path,
                    'blob_sha'       => $blobSha,
                ];
                $byBasename[$bn][] = $occ;
                $byBasenameLower[mb_strtolower($bn)][] = $occ;
            }
        }
        return [$byBasename, $byBasenameLower, $reposRead];
    }

    private function matchOne(GmudPackageFile $file, array $byBasename, array $byBasenameLower, $repos, array $reposRead): void
    {
        $bn = $file->filename;

        // Sem repositório ativo OU nenhuma árvore lida → não é possível afirmar existência: não casa.
        if ($repos->isEmpty() || empty($reposRead)) {
            $file->update([
                'match_status'   => null,
                'match_evidence' => ['reason' => $repos->isEmpty() ? 'no_repo' : 'tree_unavailable'],
            ]);
            return;
        }

        $exact = $byBasename[$bn] ?? [];

        if (count($exact) === 1) {
            $this->applySingle($file, $exact[0]);
            return;
        }
        if (count($exact) > 1) {
            $this->applyAmbiguous($file, $exact, 'multiple_occurrences');
            return;
        }

        // Exato = 0. Divergência só de caixa também é AMBÍGUA (não afirmar novo silenciosamente).
        $ci = $byBasenameLower[mb_strtolower($bn)] ?? [];
        if (! empty($ci)) {
            $this->applyAmbiguous($file, $ci, 'case_mismatch');
            return;
        }

        // Não existe em nenhum repo ativo → fonte NOVO.
        $file->update([
            'match_status'   => GmudPackageFile::MATCH_NEW,
            'matched_source_doc_id' => null,
            'matched_git_path' => null,
            'match_candidates' => null,
            'match_evidence' => [
                'reason'   => 'not_found',
                'own_blob' => $file->git_blob_sha,
                'repos_read' => $reposRead,
            ],
        ]);
    }

    /** Ocorrência única: idêntico se blob bate; senão existente (alterado). */
    private function applySingle(GmudPackageFile $file, array $occ): void
    {
        $identical = $file->git_blob_sha !== null && hash_equals((string) $occ['blob_sha'], (string) $file->git_blob_sha);
        $docId = $this->lookupSourceDocId($occ);
        $file->update([
            'match_status'          => $identical ? GmudPackageFile::MATCH_IDENTICAL : GmudPackageFile::MATCH_EXISTING,
            'matched_source_doc_id' => $docId,
            'matched_git_path'      => $occ['path'],
            'match_candidates'      => null,
            'match_evidence'        => [
                'reason'    => $identical ? 'blob_equal' : 'blob_differs',
                'own_blob'  => $file->git_blob_sha,
                'git_blob'  => $occ['blob_sha'],
                'git_path'  => $occ['path'],
                'source_repo_id' => $occ['source_repo_id'],
                'has_acervo_doc' => $docId !== null,
            ],
        ]);
    }

    /** Múltiplos candidatos → ambíguo. Nunca escolhe sozinho. */
    private function applyAmbiguous(GmudPackageFile $file, array $occurrences, string $reason): void
    {
        $candidates = array_map(function (array $occ) {
            return [
                'source_repo_id' => $occ['source_repo_id'],
                'path'           => $occ['path'],
                'blob_sha'       => $occ['blob_sha'],
                'source_doc_id'  => $this->lookupSourceDocId($occ),
                'blob_equal'     => $occ['blob_sha'],
            ];
        }, $occurrences);

        $file->update([
            'match_status'          => GmudPackageFile::MATCH_AMBIGUOUS,
            'matched_source_doc_id' => null,
            'matched_git_path'      => null,
            'match_candidates'      => $candidates,
            'match_evidence'        => [
                'reason'     => $reason,
                'own_blob'   => $file->git_blob_sha,
                'candidates' => count($candidates),
            ],
        ]);
    }

    /** Identidade real na Central (owner+repo+branch+path) → habilita "Abrir no Acervo". */
    private function lookupSourceDocId(array $occ): ?int
    {
        $doc = SourceDoc::where('owner', $occ['owner'])
            ->where('repository', $occ['repository'])
            ->where('branch', $occ['branch'])
            ->where('path', $occ['path'])
            ->value('id');
        return $doc ? (int) $doc : null;
    }
}
