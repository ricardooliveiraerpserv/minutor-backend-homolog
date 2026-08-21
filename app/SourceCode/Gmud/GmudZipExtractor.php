<?php

namespace App\SourceCode\Gmud;

use App\SourceCode\GmudSourceProcessor;

/**
 * GMUD G1 — extração SEGURA e determinística do ZIP recebido.
 *
 * Lê o ZIP EM MEMÓRIA (ZipArchive statIndex/getFromIndex sobre um tempfile só de leitura) e NUNCA
 * escreve o conteúdo extraído no disco → o zip-slip clássico (escrita fora do diretório) não se
 * aplica. Mesmo assim, valida path traversal / paths absolutos / symlinks como defesa em
 * profundidade, aplica limites anti-zip-bomb (nº de arquivos, tamanho descompactado, razão de
 * compressão) e filtra lixo de SO. Por arquivo captura sha256, git blob sha, mtime e extensão.
 *
 * NÃO executa nada. NÃO publica nada. Apenas produz a evidência para o matching (G2).
 */
class GmudZipExtractor
{
    /** Extensões reconhecidas como FONTE (herda o domínio real do processador legado). */
    public const SOURCE_EXT = GmudSourceProcessor::SOURCE_EXT;

    /**
     * Extensões AUTORIZADAS num pacote governado (allowlist). Fontes + companheiros já autorizados
     * no cadastro de anexos (registry HELPDESK_TICKET_COMMENT) — não inventamos extensões novas.
     * Qualquer coisa fora disso (ex.: exe/sh/dll/bin) é REJEITADA como 'disallowed_ext'.
     */
    public const COMPANION_EXT = ['prx', 'prg', 'ppr', 'ppx', 'ppp', 'tlp', 'apo', 'apu', 'sql', 'xml', 'json', 'md', 'txt'];

    private function limits(): array
    {
        return [
            'max_files'             => (int) config('services.gmud.max_files', 5000),
            'max_total_uncompressed' => (int) config('services.gmud.max_total_bytes', 200 * 1024 * 1024),
            'max_single_uncompressed' => (int) config('services.gmud.max_single_bytes', 50 * 1024 * 1024),
            'max_ratio'             => (int) config('services.gmud.max_compression_ratio', 200),
            'ratio_floor'           => (int) config('services.gmud.ratio_floor_bytes', 4096),
        ];
    }

    /**
     * @return array{files: array<int,array<string,mixed>>, skipped: array<int,array{path:string,reason:string}>, stats: array<string,int>}
     * @throws GmudExtractionException  em falha estrutural/segurança (corrompido, vazio, bomba, limites).
     */
    public function extract(string $bytes): array
    {
        $lim = $this->limits();
        $tmp = tempnam(sys_get_temp_dir(), 'gmudpkg');
        file_put_contents($tmp, $bytes);

        $zip = new \ZipArchive();
        $open = $zip->open($tmp, \ZipArchive::CHECKCONS);
        if ($open !== true) {
            @unlink($tmp);
            throw new GmudExtractionException('corrupt_zip', 'ZIP corrompido ou ilegível (código ' . $open . ').');
        }

        $files = [];
        $skipped = [];
        $totalUncompressed = 0;
        $totalCompressed = 0;
        $realCount = 0;

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    continue;
                }
                $name = (string) ($stat['name'] ?? '');
                if ($name === '' || str_ends_with($name, '/')) {
                    continue; // diretório
                }
                if ($this->isJunk($name)) {
                    $skipped[] = ['path' => $name, 'reason' => 'junk'];
                    continue;
                }
                if ($this->isUnsafePath($name)) {
                    // path traversal / absoluto / bytes de controle → nunca vira arquivo do pacote.
                    $skipped[] = ['path' => $name, 'reason' => 'unsafe_path'];
                    continue;
                }
                if ($this->isSymlink($zip, $i)) {
                    $skipped[] = ['path' => $name, 'reason' => 'symlink'];
                    continue;
                }

                $size = (int) ($stat['size'] ?? 0);
                $comp = (int) ($stat['comp_size'] ?? 0);
                if ($size > $lim['max_single_uncompressed']) {
                    throw new GmudExtractionException('file_too_large', 'Arquivo excede o limite individual descompactado.');
                }
                $totalUncompressed += $size;
                $totalCompressed += $comp;
                $realCount++;
                if ($realCount > $lim['max_files']) {
                    throw new GmudExtractionException('too_many_files', 'Pacote excede o número máximo de arquivos permitido.');
                }
                if ($totalUncompressed > $lim['max_total_uncompressed']) {
                    throw new GmudExtractionException('total_too_large', 'Pacote excede o tamanho total descompactado permitido.');
                }

                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (! $this->isAllowedExt($ext)) {
                    $skipped[] = ['path' => $name, 'reason' => 'disallowed_ext'];
                    continue;
                }

                $content = $zip->getFromIndex($i);
                if ($content === false) {
                    $skipped[] = ['path' => $name, 'reason' => 'read_failed'];
                    continue;
                }

                $mtime = (int) ($stat['mtime'] ?? 0);
                $files[] = [
                    'path_in_zip'  => $name,
                    'filename'     => basename($name),
                    'extension'    => $ext !== '' ? $ext : null,
                    'size_bytes'   => strlen($content),
                    'sha256'       => hash('sha256', $content),
                    'git_blob_sha' => $this->gitBlobSha($content),
                    'mtime'        => $mtime > 0 ? \Illuminate\Support\Carbon::createFromTimestamp($mtime) : null,
                    'is_source'    => in_array($ext, self::SOURCE_EXT, true),
                ];
            }
        } finally {
            $zip->close();
            @unlink($tmp);
        }

        if ($realCount === 0 && empty($files)) {
            throw new GmudExtractionException('empty_zip', 'ZIP vazio ou sem arquivos aproveitáveis.');
        }

        // Bomba de descompressão: razão só é confiável acima de um piso de bytes comprimidos.
        if ($totalCompressed >= $lim['ratio_floor'] && $totalCompressed > 0) {
            $ratio = $totalUncompressed / $totalCompressed;
            if ($ratio > $lim['max_ratio']) {
                throw new GmudExtractionException('zip_bomb', 'Razão de compressão suspeita (possível zip bomb).');
            }
        }

        return [
            'files'   => $files,
            'skipped' => $skipped,
            'stats'   => [
                'entries'            => $realCount,
                'total_uncompressed' => $totalUncompressed,
                'total_compressed'   => $totalCompressed,
            ],
        ];
    }

    /** git hash-object: sha1("blob <len>\0<bytes>") sobre os bytes CRUS — casa com treeBlobShas. */
    public function gitBlobSha(string $content): string
    {
        return sha1('blob ' . strlen($content) . "\0" . $content);
    }

    private function isAllowedExt(string $ext): bool
    {
        return in_array($ext, self::SOURCE_EXT, true) || in_array($ext, self::COMPANION_EXT, true);
    }

    /** Lixo de SO (macOS __MACOSX/._*, .DS_Store; Windows Thumbs.db). */
    private function isJunk(string $path): bool
    {
        if (str_starts_with($path, '__MACOSX/') || str_contains($path, '/__MACOSX/')) {
            return true;
        }
        $base = basename($path);
        return str_starts_with($base, '._') || $base === '.DS_Store' || strcasecmp($base, 'Thumbs.db') === 0;
    }

    /** Path traversal, path absoluto (unix/windows), ou bytes de controle. */
    private function isUnsafePath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0")) {
            return true;
        }
        // Absoluto: /etc/... ou C:\... ou \\servidor
        if ($path[0] === '/' || $path[0] === '\\' || preg_match('#^[A-Za-z]:[\\\\/]#', $path)) {
            return true;
        }
        $norm = str_replace('\\', '/', $path);
        foreach (explode('/', $norm) as $seg) {
            if ($seg === '..') {
                return true;
            }
        }
        return false;
    }

    /** Symlink dentro do ZIP (unix mode S_IFLNK nos atributos externos). */
    private function isSymlink(\ZipArchive $zip, int $index): bool
    {
        $opsys = null;
        $attr = null;
        if (! $zip->getExternalAttributesIndex($index, $opsys, $attr)) {
            return false;
        }
        if ($opsys !== \ZipArchive::OPSYS_UNIX) {
            return false;
        }
        $mode = ($attr >> 16) & 0xFFFF;
        return ($mode & 0xF000) === 0xA000; // S_IFLNK
    }
}
