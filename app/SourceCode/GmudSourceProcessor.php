<?php

namespace App\SourceCode;

use App\Attachments\Storage\StorageProvider;
use App\Models\Attachment;
use App\Models\ClientSourceRepo;
use App\Models\HelpDeskTicket;
use App\Models\HelpDeskTicketComment;
use App\SourceCode\Exceptions\SourceIntegrationException;
use Illuminate\Support\Facades\Log;

/**
 * Varredura de fonte na GMUD: ao resolver um chamado com GMUD, lê o(s) .zip anexado(s) na
 * solução, identifica os fontes AdvPL/TL++, commita no repositório do cliente (preservando a
 * estrutura, sobrescrevendo) e grava uma interação interna de auditoria + a flag no chamado.
 * Best-effort: nunca deve derrubar a submissão da solução.
 */
class GmudSourceProcessor
{
    /** Extensões reconhecidas como FONTE (programa + headers) — o compilador appserver aceita. */
    public const SOURCE_EXT = ['prw', 'prx', 'tlpp', 'apw', 'apl', 'aph', 'prg', 'ch', 'th'];

    public function __construct(private GithubAppAuth $auth)
    {
    }

    public function process(HelpDeskTicket $ticket, HelpDeskTicketComment $comment): void
    {
        $storage = app(StorageProvider::class);
        $zips = Attachment::query()->forEntity('HELPDESK_TICKET_COMMENT', $comment->id)->get()
            ->filter(fn (Attachment $a) => $this->isZip($a));

        $inventory = [];   // caminho => tamanho (bytes) — TUDO que veio no(s) pacote(s)
        $sources = [];     // caminho => conteúdo (só os fontes reconhecidos)
        foreach ($zips as $z) {
            try {
                $bytes = $storage->get($z->storage_path);
            } catch (\Throwable $e) {
                Log::warning('gmud_source.zip_read_failed', ['attachment' => $z->id, 'error' => $e->getMessage()]);
                continue;
            }
            [$entries, $src] = $this->readZip($bytes);
            $inventory += $entries;
            $sources += $src;
        }

        $repo = $ticket->customer_id
            ? ClientSourceRepo::where('customer_id', $ticket->customer_id)->where('active', true)->first()
            : null;

        $status = 'sem_fonte';
        $commitSha = null;
        $error = null;

        if (!empty($sources)) {
            if (!$repo) {
                $status = 'sem_repo';
            } elseif ($repo->needs_review) {
                // Vínculo pendente de verificação (repo pré-existente) → NÃO commita até o admin confirmar.
                $status = 'repo_pendente_verificacao';
            } elseif (!$this->auth->isConfigured()) {
                $status = 'erro';
                $error = 'GitHub App não configurada.';
            } else {
                // Opção B (doc v2): o commit versiona APENAS o código-fonte (GitHub = fonte da verdade
                // do código). A documentação NÃO é mais commitada aqui — ela passa a ser gerada e
                // persistida no Minutor pelo motor de documentação (fases seguintes). Sem doc no commit.
                $basePath = $repo->normalizedBasePath();
                $branch = $repo->branch ?: 'main';
                $files = [];
                foreach ($sources as $path => $content) {
                    $files[($basePath !== '' ? $basePath . '/' : '') . $path] = $content;
                }
                try {
                    // Antes do commit: captura o HEAD (parent do diff) e a versão anterior de cada fonte.
                    $parentSha = $this->auth->getBranchHeadSha($repo->owner, $repo->repository, $branch);
                    $oldCode = [];
                    foreach ($sources as $path => $content) {
                        $oldCode[$path] = $this->auth->getFileContent($repo->owner, $repo->repository, $branch, ($basePath !== '' ? $basePath . '/' : '') . $path);
                    }
                    $commitSha = $this->auth->commitFiles($repo->owner, $repo->repository, $branch, $files, $this->commitMessage($ticket, $comment));
                    $status = 'atualizado';
                    // Pipeline de documentação por fonte (best-effort — nunca afeta a GMUD).
                    $this->runDocPipeline($ticket, $comment, $repo, $basePath, $branch, $sources, $oldCode, $commitSha, $parentSha);
                } catch (SourceIntegrationException $e) {
                    $status = $e->errorCode === 'CONTENTS_WRITE_NOT_PERMITTED' ? 'pendente_permissao' : 'erro';
                    $error = $e->getMessage();
                } catch (\Throwable $e) {
                    $status = 'erro';
                    $error = $e->getMessage();
                }
            }
        }

        // Interação interna de auditoria da varredura + flag no chamado. Nota interna normal.
        $ticket->comments()->create([
            'author_user_id' => $comment->author_user_id,
            'body'           => $this->report($ticket, $comment, $inventory, array_keys($sources), $repo, $status, $commitSha, $error),
            'visibility'     => 'internal',
            'channel'        => 'interno',
            'is_system'      => false,
        ]);
        $ticket->gmud_source_status = $status;
        $ticket->saveQuietly();
    }

    /** Lixo de SO que vem dentro de zips (não é fonte nem conteúdo real do pacote). */
    private function isJunk(string $path): bool
    {
        if (str_starts_with($path, '__MACOSX/') || str_contains($path, '/__MACOSX/')) {
            return true;
        }
        $baseName = basename($path);
        return str_starts_with($baseName, '._') || $baseName === '.DS_Store' || strcasecmp($baseName, 'Thumbs.db') === 0;
    }

    private function isZip(Attachment $a): bool
    {
        $ext = strtolower((string) ($a->extension ?: pathinfo((string) $a->original_name, PATHINFO_EXTENSION)));
        return $ext === 'zip' || str_contains(strtolower((string) $a->mime_type), 'zip');
    }

    /** @return array{0: array<string,int>, 1: array<string,string>} [inventário, fontes] */
    private function readZip(string $bytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'gmudzip');
        file_put_contents($tmp, $bytes);
        $inventory = [];
        $sources = [];
        $zip = new \ZipArchive();
        if ($zip->open($tmp) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = (string) ($stat['name'] ?? '');
                if ($name === '' || str_ends_with($name, '/')) {
                    continue; // diretório
                }
                if ($this->isJunk($name)) {
                    continue; // lixo do SO (macOS __MACOSX/._*, .DS_Store; Windows Thumbs.db) — não é conteúdo
                }
                $inventory[$name] = (int) ($stat['size'] ?? 0);
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (in_array($ext, self::SOURCE_EXT, true)) {
                    $content = $zip->getFromIndex($i);
                    if ($content !== false) {
                        $sources[$name] = $content;
                    }
                }
            }
            $zip->close();
        }
        @unlink($tmp);
        return [$inventory, $sources];
    }

    /** Documenta cada fonte commitado. Semântica inline só nos primeiros N (teto de latência); o
     *  resto fica 'analyzing' (determinística pronta) e conclui via `source-doc:reprocess`. */
    private function runDocPipeline(HelpDeskTicket $ticket, HelpDeskTicketComment $comment, ClientSourceRepo $repo, string $basePath, string $branch, array $sources, array $oldCode, string $commitSha, ?string $parentSha): void
    {
        $pipeline = app(\App\SourceCode\SourceDocPipeline::class);
        $max = (int) config('services.source_doc.inline_semantic_max', 3);
        $responsibleId = $ticket->assignee_id ?: $comment->author_user_id;
        $responsavel = optional($ticket->assignee)->name ?: optional($comment->author)->name ?: '—';
        $i = 0;
        foreach ($sources as $path => $content) {
            $repoPath = ($basePath !== '' ? $basePath . '/' : '') . $path;
            try {
                $pipeline->processFile([
                    'customer_id' => $ticket->customer_id, 'source_repo_id' => $repo->id,
                    'owner' => $repo->owner, 'repository' => $repo->repository, 'branch' => $branch, 'path' => $repoPath,
                    'tipo' => $repo->tipo, 'new_code' => $content, 'old_code' => $oldCode[$path] ?? null,
                    'source_commit_sha' => $commitSha, 'parent_source_commit_sha' => $parentSha,
                    'gmud_id' => $ticket->id, 'ticket_number' => $ticket->ticket_number,
                    'responsible_user_id' => $responsibleId, 'responsavel' => $responsavel,
                ], $i < $max);
            } catch (\Throwable $e) {
                Log::warning('gmud_source.doc_pipeline_failed', ['path' => $repoPath, 'error' => $e->getMessage()]);
            }
            $i++;
        }
    }

    private function commitMessage(HelpDeskTicket $ticket, HelpDeskTicketComment $comment): string
    {
        $num = $ticket->ticket_number ?: ('#' . $ticket->id);
        $resp = optional($ticket->assignee)->name ?: optional($comment->author)->name ?: '—';
        return "GMUD chamado {$num} — responsável: {$resp}";
    }

    private function report(HelpDeskTicket $ticket, HelpDeskTicketComment $comment, array $inventory, array $committed, ?ClientSourceRepo $repo, string $status, ?string $sha, ?string $error): string
    {
        $num = $ticket->ticket_number ?: ('#' . $ticket->id);
        $resp = optional($ticket->assignee)->name ?: optional($comment->author)->name ?: '—';
        $committedSet = array_flip($committed);

        $lines = ["🔎 Varredura de fonte — GMUD chamado {$num}", ''];

        $total = count($inventory);
        if ($total === 0) {
            $lines[] = '📦 Nenhum pacote (.zip) anexado à solução.';
        } else {
            $lines[] = "📦 Conteúdo do pacote: {$total} arquivo(s)";
            foreach ($inventory as $path => $size) {
                $isSrc = isset($committedSet[$path]);
                $lines[] = '   ' . ($isSrc ? '✅' : '⏭️') . " {$path}  (" . $this->human($size) . ')'
                    . ($isSrc ? '' : '  — ignorado (não é fonte)');
            }
        }
        $lines[] = '';

        $n = count($committed);
        switch ($status) {
            case 'atualizado':
                $lines[] = "✅ Gravados no Git ({$n} fonte(s)): {$repo->owner}/{$repo->repository} @ {$repo->branch} · commit " . substr((string) $sha, 0, 7);
                break;
            case 'sem_fonte':
                $lines[] = 'ℹ️ Nenhum fonte reconhecido no pacote — nada gravado no Git.';
                break;
            case 'sem_repo':
                $lines[] = "⚠️ {$n} fonte(s) detectado(s), mas o cliente NÃO tem repositório de destino configurado — nada gravado.";
                break;
            case 'repo_pendente_verificacao':
                $lines[] = "⚠️ {$n} fonte(s) detectado(s), mas o repositório do cliente está PENDENTE DE VERIFICAÇÃO (vínculo automático sobre repo pré-existente). Confirme o repositório no cadastro do cliente antes do commit — nada gravado.";
                break;
            case 'pendente_permissao':
                $lines[] = "⚠️ {$n} fonte(s) detectado(s), mas o commit está PENDENTE: a GitHub App precisa de \"Contents: Read and write\". Nada gravado ainda.";
                break;
            default:
                $lines[] = "⚠️ Falha ao gravar no Git ({$n} fonte(s) detectado(s), não gravados): " . ($error ?: 'erro desconhecido');
        }

        $lines[] = '';
        $lines[] = "👤 Responsável: {$resp}";
        return implode("\n", $lines);
    }

    private function human(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return round($bytes / 1024 / 1024, 1) . ' MB';
    }
}
