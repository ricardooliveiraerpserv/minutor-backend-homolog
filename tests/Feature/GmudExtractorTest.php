<?php

namespace Tests\Feature;

use App\SourceCode\Gmud\GmudExtractionException;
use App\SourceCode\Gmud\GmudZipExtractor;
use Tests\TestCase;

/**
 * GMUD G1 — extração SEGURA. Prova hashes, mtime, allowlist, filtragem de lixo e as defesas contra
 * path traversal / paths absolutos / symlinks / zip bomb / limites / zip corrompido ou vazio.
 * Nenhum teste aqui toca Git ou banco — é a fronteira de segurança da extração.
 */
class GmudExtractorTest extends TestCase
{
    private function extractor(): GmudZipExtractor
    {
        return app(GmudZipExtractor::class);
    }

    /** @param array<string,string> $entries path=>content */
    private function zip(array $entries, ?callable $mutate = null): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ztest') . '.zip';
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        if ($mutate) {
            $mutate($zip);
        }
        $zip->close();
        $bytes = file_get_contents($tmp);
        @unlink($tmp);
        return $bytes;
    }

    public function test_extracts_multiple_sources_with_hashes_and_flags(): void
    {
        $bytes = $this->zip([
            'FONTEA.prw' => "User Function A()\nReturn",
            'sub/FONTEB.tlpp' => "class B\nendclass",
            'README.md' => '# doc',
        ]);
        $res = $this->extractor()->extract($bytes);

        $this->assertCount(3, $res['files']);
        $byName = collect($res['files'])->keyBy('filename');

        $a = $byName['FONTEA.prw'];
        $this->assertTrue($a['is_source']);
        $this->assertSame('prw', $a['extension']);
        $this->assertSame(hash('sha256', "User Function A()\nReturn"), $a['sha256']);
        // git blob sha = sha1("blob <len>\0<bytes>")
        $expected = sha1('blob ' . strlen("User Function A()\nReturn") . "\0" . "User Function A()\nReturn");
        $this->assertSame($expected, $a['git_blob_sha']);

        $this->assertTrue($byName['FONTEB.tlpp']['is_source']);
        // .md é companheiro autorizado, porém NÃO é fonte.
        $this->assertFalse($byName['README.md']['is_source']);
    }

    public function test_preserves_mtime_from_zip(): void
    {
        // ZipArchive grava mtime = agora; basta provar que veio um Carbon coerente (>0).
        $res = $this->extractor()->extract($this->zip(['X.prw' => 'a']));
        $this->assertNotNull($res['files'][0]['mtime']);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $res['files'][0]['mtime']);
    }

    public function test_ignores_os_junk(): void
    {
        $bytes = $this->zip([
            'GOOD.prw' => 'x',
            '__MACOSX/GOOD.prw' => 'junk',
            '._GOOD.prw' => 'junk',
            '.DS_Store' => 'junk',
            'Thumbs.db' => 'junk',
        ]);
        $res = $this->extractor()->extract($bytes);
        $this->assertCount(1, $res['files']);
        $this->assertSame('GOOD.prw', $res['files'][0]['filename']);
        $reasons = collect($res['skipped'])->pluck('reason')->all();
        $this->assertContains('junk', $reasons);
    }

    public function test_rejects_path_traversal_and_absolute_paths(): void
    {
        $bytes = $this->zip([
            'OK.prw' => 'x',
            '../evil.prw' => 'x',
            'a/../../evil2.prw' => 'x',
            '/etc/evil.prw' => 'x',
        ]);
        $res = $this->extractor()->extract($bytes);
        $names = collect($res['files'])->pluck('filename')->all();
        $this->assertSame(['OK.prw'], $names);
        $unsafe = collect($res['skipped'])->where('reason', 'unsafe_path')->count();
        $this->assertGreaterThanOrEqual(3, $unsafe);
    }

    public function test_rejects_symlink_entries(): void
    {
        $bytes = $this->zip(['OK.prw' => 'x'], function (\ZipArchive $zip) {
            $zip->addFromString('link.prw', '/etc/passwd');
            // marca link.prw como symlink (S_IFLNK) nos atributos externos unix.
            $zip->setExternalAttributesName('link.prw', \ZipArchive::OPSYS_UNIX, (0120777 << 16));
        });
        $res = $this->extractor()->extract($bytes);
        $this->assertSame(['OK.prw'], collect($res['files'])->pluck('filename')->all());
        $this->assertTrue(collect($res['skipped'])->contains(fn ($s) => $s['reason'] === 'symlink'));
    }

    public function test_rejects_disallowed_extension(): void
    {
        $bytes = $this->zip(['OK.prw' => 'x', 'evil.exe' => 'MZ', 'run.sh' => '#!/bin/sh']);
        $res = $this->extractor()->extract($bytes);
        $this->assertSame(['OK.prw'], collect($res['files'])->pluck('filename')->all());
        $this->assertSame(2, collect($res['skipped'])->where('reason', 'disallowed_ext')->count());
    }

    public function test_name_collision_keeps_both_occurrences(): void
    {
        $bytes = $this->zip(['dir1/DUP.prw' => 'a', 'dir2/DUP.prw' => 'b']);
        $res = $this->extractor()->extract($bytes);
        $this->assertCount(2, $res['files']);
        $paths = collect($res['files'])->pluck('path_in_zip')->sort()->values()->all();
        $this->assertSame(['dir1/DUP.prw', 'dir2/DUP.prw'], $paths);
    }

    public function test_empty_zip_throws(): void
    {
        // ZIP válido porém vazio = só o registro End-Of-Central-Directory (22 bytes).
        $emptyZip = "PK\x05\x06" . str_repeat("\0", 18);
        $this->expectException(GmudExtractionException::class);
        $this->extractor()->extract($emptyZip);
    }

    public function test_corrupt_zip_throws(): void
    {
        $this->expectException(GmudExtractionException::class);
        $this->extractor()->extract('not a zip at all');
    }

    public function test_too_many_files_throws(): void
    {
        config(['services.gmud.max_files' => 1]);
        $this->expectException(GmudExtractionException::class);
        $this->extractor()->extract($this->zip(['A.prw' => 'a', 'B.prw' => 'b']));
    }

    public function test_total_size_limit_throws(): void
    {
        config(['services.gmud.max_total_bytes' => 4]);
        $this->expectException(GmudExtractionException::class);
        $this->extractor()->extract($this->zip(['A.prw' => 'aaaaaaaaaa']));
    }

    public function test_zip_bomb_ratio_throws(): void
    {
        config(['services.gmud.max_compression_ratio' => 5, 'services.gmud.ratio_floor_bytes' => 1]);
        // 200 KB de zeros comprime muito → razão >> 5.
        $this->expectException(GmudExtractionException::class);
        $this->extractor()->extract($this->zip(['big.prw' => str_repeat("\0", 200 * 1024)]));
    }

    public function test_git_blob_sha_helper_matches_git_algorithm(): void
    {
        $ex = $this->extractor();
        $this->assertSame(sha1("blob 5\0hello"), $ex->gitBlobSha('hello'));
        $this->assertSame(sha1("blob 0\0"), $ex->gitBlobSha(''));
    }
}
