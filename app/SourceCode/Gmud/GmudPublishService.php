<?php

namespace App\SourceCode\Gmud;

use App\Attachments\Storage\StorageProvider;
use App\Models\ClientSourceRepo;
use App\Models\GmudPackage;
use App\Models\GmudPackageFile;
use App\Models\HelpDeskTicketEvent;
use App\Models\User;
use App\SourceCode\GithubAppAuth;
use App\SourceCode\SourceRepoResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * GMUD G4/G6/G7 — PUBLICAÇÃO governada. Só aqui existe commit no Git, e sempre com destino
 * escolhido e aceite explícito do usuário (nunca automático).
 *
 * Regras de destino (decididas com o usuário):
 *  - NOVO      → vai para a PASTA escolhida (um diretório para todos os novos).
 *  - EXISTENTE → grava por cima no path atual do Git (preservado).
 *  - AMBÍGUO   → usa a ocorrência que o usuário escolheu (nunca decide sozinho).
 *  - IDÊNTICO  → ignorado (nada muda).
 * Publicação ATÔMICA: valida tudo → 1 commit multi-arquivo (GithubAppAuth::commitFiles). Erro em
 * qualquer arquivo = 0 publicados.
 */
class GmudPublishService
{
    public function __construct(
        private GithubAppAuth $auth,
        private GmudZipExtractor $extractor,
        private SourceRepoResolver $resolver,
        private StorageProvider $storage,
    ) {
    }

    /**
     * @param array<int,string> $resolutions  file_id => git_path escolhido (só p/ ambíguos)
     * @param array{classification?:?string, project_name?:?string, folders?:array<int,string>} $meta
     *        folders = file_id => pasta específica (por-arquivo) p/ NOVOS; cai no $destFolder global se ausente.
     * @return array{commit_sha:string, repo:string, branch:string, published:int, skipped:int, files:array}
     */
    public function publish(GmudPackage $package, ?int $repoId, string $destFolder, array $resolutions, User $actor, array $meta = []): array
    {
        $folders = $meta['folders'] ?? [];
        if ($package->status === GmudPackage::STATUS_PUBLISHED) {
            throw new GmudPublishException('Este pacote já foi publicado.');
        }
        if ($package->status !== GmudPackage::STATUS_ANALYZED) {
            throw new GmudPublishException('O pacote ainda não está analisado.');
        }

        $repo = $this->resolveRepo($package, $repoId);
        $customer = $package->customer;
        if (! $customer) {
            throw new GmudPublishException('Pacote sem cliente — não é possível resolver o repositório.');
        }
        if ($repo->needs_review) {
            throw new GmudPublishException('O repositório do cliente está pendente de verificação. Confirme no cadastro antes de publicar.');
        }
        if (! $this->auth->isConfigured()) {
            throw new GmudPublishException('A GitHub App não está configurada.');
        }

        $owner = $repo->owner;
        $repository = $repo->repository;
        $branch = $repo->branch ?: 'main';
        $destFolder = trim(str_replace('\\', '/', $destFolder), '/');

        // 1) Monta o PLANO por arquivo (sem tocar no Git ainda).
        $plan = [];         // file_id => ['action','dest','path_in_zip']
        $destSeen = [];     // dest => file_id (anti-colisão)
        $skipped = 0;
        foreach ($package->files()->where('is_source', true)->get() as $f) {
            [$action, $dest] = $this->resolveDestination($f, $destFolder, $repo, $resolutions, $folders);
            if ($action === GmudPackageFile::ACTION_SKIP) {
                $skipped++;
                $f->update(['action' => GmudPackageFile::ACTION_SKIP, 'dest_git_path' => null]);
                continue;
            }
            // Segurança: rejeita ../ e fora do base_path do repo (reusa a mesma barreira do acervo).
            $this->resolver->assertAuthorized($customer, $owner, $repository, $dest);
            if (isset($destSeen[$dest])) {
                throw new GmudPublishException("Colisão de destino: dois fontes iriam para \"{$dest}\". Ajuste a pasta.");
            }
            $destSeen[$dest] = $f->id;
            $plan[$f->id] = ['action' => $action, 'dest' => $dest, 'path_in_zip' => $f->path_in_zip];
        }

        if (empty($plan)) {
            throw new GmudPublishException('Nada a publicar: todos os fontes já estão idênticos ao Git.');
        }

        // 2) Conteúdo dos fontes = ZIP imutável (não guardamos bytes no banco).
        $bytes = $this->storage->get($package->attachment->storage_path);
        $contents = $this->extractor->contentsFor($bytes, array_values(array_column($plan, 'path_in_zip')));
        $filesMap = [];
        foreach ($plan as $fid => $p) {
            if (! array_key_exists($p['path_in_zip'], $contents)) {
                throw new GmudPublishException("Não foi possível ler o conteúdo de \"{$p['path_in_zip']}\" no pacote.");
            }
            $filesMap[$p['dest']] = $contents[$p['path_in_zip']];
        }

        // 3) COMMIT ATÔMICO (tudo-ou-nada). commitFiles monta a árvore inteira e só então move a ref.
        $package->update(['status' => GmudPackage::STATUS_PUBLISHING, 'source_repo_id' => $repo->id, 'error' => null]);
        try {
            $blobShas = [];
            $commitSha = $this->auth->commitFiles($owner, $repository, $branch, $filesMap, $this->commitMessage($package, count($plan), $actor), $blobShas);
        } catch (\Throwable $e) {
            $package->update(['status' => GmudPackage::STATUS_PUBLISH_FAILED, 'error' => mb_substr($e->getMessage(), 0, 300)]);
            $this->audit($package, 'gmud_package_publish_failed', ['error' => mb_substr($e->getMessage(), 0, 120), 'committed' => false]);
            throw $e;
        }

        // 4) Persiste resultado por arquivo + estado do pacote.
        DB::transaction(function () use ($plan, $blobShas, $package, $repo, $destFolder) {
            foreach ($plan as $fid => $p) {
                GmudPackageFile::where('id', $fid)->update([
                    'action'             => $p['action'],
                    'dest_git_path'      => $p['dest'],
                    'published_blob_sha' => $blobShas[$p['dest']] ?? null,
                ]);
            }
            $package->update([
                'status'         => GmudPackage::STATUS_PUBLISHED,
                'source_repo_id' => $repo->id,
                'project_folder' => $destFolder ?: null,
                'classification' => $meta['classification'] ?? $package->classification,
                'project_name'   => $meta['project_name'] ?? $package->project_name,
            ]);
        });

        $this->audit($package, 'gmud_package_published', [
            'commit_sha' => $commitSha, 'repo' => "{$owner}/{$repository}", 'branch' => $branch,
            'published' => count($plan), 'skipped' => $skipped, 'committed' => true,
        ]);
        // Reaproveita o selo legado de status de fonte no chamado.
        if ($package->ticket) {
            $package->ticket->forceFill(['gmud_source_status' => 'atualizado'])->saveQuietly();
        }

        return [
            'commit_sha' => $commitSha,
            'repo'       => "{$owner}/{$repository}",
            'branch'     => $branch,
            'published'  => count($plan),
            'skipped'    => $skipped,
            'files'      => array_map(fn ($p) => ['dest' => $p['dest'], 'action' => $p['action']], $plan),
        ];
    }

    /** Destino + ação de um arquivo, conforme a situação do matching. $folders = pasta por-arquivo (NOVOS). */
    private function resolveDestination(GmudPackageFile $f, string $destFolder, ClientSourceRepo $repo, array $resolutions, array $folders = []): array
    {
        switch ($f->match_status) {
            case GmudPackageFile::MATCH_IDENTICAL:
                return [GmudPackageFile::ACTION_SKIP, null];

            case GmudPackageFile::MATCH_EXISTING:
                return [GmudPackageFile::ACTION_MODIFY, $f->matched_git_path];

            case GmudPackageFile::MATCH_AMBIGUOUS:
                $chosen = $resolutions[$f->id] ?? null;
                $candPaths = collect($f->match_candidates ?? [])->pluck('path')->all();
                if (! $chosen || ! in_array($chosen, $candPaths, true)) {
                    throw new GmudPublishException("Arquivo ambíguo não resolvido: \"{$f->filename}\". Escolha a ocorrência de destino.");
                }
                return [GmudPackageFile::ACTION_MODIFY, $chosen];

            // new ou null (não encontrado / sem repo na análise): trata como NOVO → pasta específica
            // do arquivo (se o consultor vinculou uma), senão a pasta global escolhida.
            default:
                $folder = array_key_exists($f->id, $folders) ? trim(str_replace('\\', '/', (string) $folders[$f->id]), '/') : $destFolder;
                $dest = $folder !== '' ? "{$folder}/{$f->filename}" : $f->filename;
                // Se o repo tem base_path e a pasta escolhida não o inclui, prefixa (mantém dentro da raiz autorizada).
                $base = $repo->normalizedBasePath();
                if ($base !== '' && ! str_starts_with($dest . '/', $base . '/')) {
                    $dest = "{$base}/{$dest}";
                }
                return [GmudPackageFile::ACTION_ADD, $dest];
        }
    }

    /** Diretórios existentes do repo (p/ o seletor de pasta) + base_path. Git ao vivo (treeBlobShas). */
    public function directories(GmudPackage $package, ?int $repoId): array
    {
        $repo = $this->resolveRepo($package, $repoId);
        $branch = $repo->branch ?: 'main';
        $dirs = [];
        try {
            foreach (array_keys($this->auth->treeBlobShas($repo->owner, $repo->repository, $branch)) as $path) {
                $dir = trim((string) dirname($path), '/');
                if ($dir !== '' && $dir !== '.') {
                    $dirs[$dir] = true;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('gmud_publish.dirs_failed', ['repo' => $repo->id, 'error' => $e->getMessage()]);
        }
        $list = array_keys($dirs);
        sort($list);
        return [
            'repo'      => "{$repo->owner}/{$repo->repository}",
            'repo_id'   => $repo->id,
            'branch'    => $branch,
            'base_path' => $repo->normalizedBasePath(),
            'dirs'      => $list,
        ];
    }

    /** Repo de destino: o repo_id informado (do cliente, ativo) ou o único ativo não-pendente. */
    private function resolveRepo(GmudPackage $package, ?int $repoId): ClientSourceRepo
    {
        $q = ClientSourceRepo::where('customer_id', $package->customer_id)->where('active', true);
        if ($repoId) {
            $repo = (clone $q)->where('id', $repoId)->first();
            if (! $repo) {
                throw new GmudPublishException('Repositório inválido para este cliente.');
            }
            return $repo;
        }
        $repos = $q->where('needs_review', false)->get();
        if ($repos->count() === 1) {
            return $repos->first();
        }
        if ($repos->isEmpty()) {
            throw new GmudPublishException('O cliente não tem repositório de destino configurado.');
        }
        throw new GmudPublishException('O cliente tem vários repositórios ativos — informe qual usar.');
    }

    private function commitMessage(GmudPackage $package, int $count, User $actor): string
    {
        $ticket = $package->ticket;
        $num = $ticket && $ticket->ticket_number ? $ticket->ticket_number : ('#' . $package->ticket_id);
        $resp = $actor->name ?: '—';
        return "GMUD chamado {$num} — publicação governada ({$count} fonte(s)) — resp: {$resp}";
    }

    private function audit(GmudPackage $package, string $type, array $meta): void
    {
        try {
            HelpDeskTicketEvent::log($package->ticket_id, $type, ['meta' => array_merge(['package_id' => $package->id], $meta)]);
        } catch (\Throwable $e) {
            Log::warning('gmud_publish.audit_failed', ['package' => $package->id, 'error' => $e->getMessage()]);
        }
    }
}
