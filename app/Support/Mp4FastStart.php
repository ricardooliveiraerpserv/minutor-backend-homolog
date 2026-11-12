<?php

namespace App\Support;

/**
 * "faststart" de MP4 em PHP puro (mesma lógica do qt-faststart): move o átomo `moov` para
 * ANTES do `mdat` e corrige os offsets dos chunks (stco/co64). Sem isso o navegador precisa
 * baixar o FIM do arquivo antes de começar a tocar — o vídeo fica preto/parado no 0:00.
 * Sem perda: não re-encoda nada. Retorna true se gerou $out; false se já estava ok/não é MP4.
 */
class Mp4FastStart
{
    private const CONTAINERS = ['moov', 'trak', 'mdia', 'minf', 'stbl'];

    /** Lista os átomos de topo: [type, offset, size, headerSize]. */
    private static function topAtoms($fh, int $fileSize): array
    {
        $atoms = []; $off = 0;
        while ($off + 8 <= $fileSize) {
            fseek($fh, $off);
            $h = fread($fh, 8);
            if (strlen($h) < 8) break;
            ['size' => $size, 'type' => $type] = unpack('Nsize/a4type', $h);
            $hdr = 8;
            if ($size === 1) { $size = self::u64(fread($fh, 8)); $hdr = 16; }
            elseif ($size === 0) { $size = $fileSize - $off; }
            if ($size < $hdr || $off + $size > $fileSize) return []; // arquivo estranho → não mexe
            $atoms[] = [$type, $off, $size, $hdr];
            $off += $size;
        }
        return $atoms;
    }

    private static function u64(string $b): int
    {
        $p = unpack('Nhi/Nlo', $b);
        return ($p['hi'] << 32) | $p['lo'];
    }

    /** Soma $delta em todos os stco/co64 dentro do moov (recursivo nos containers). */
    private static function patch(string &$buf, int $start, int $end, int $delta): bool
    {
        $off = $start;
        while ($off + 8 <= $end) {
            ['size' => $size, 'type' => $type] = unpack('Nsize/a4type', substr($buf, $off, 8));
            $hdr = 8;
            if ($size === 1) { $size = self::u64(substr($buf, $off + 8, 8)); $hdr = 16; }
            if ($size < 8 || $off + $size > $end) return false;
            if (in_array($type, self::CONTAINERS, true)) {
                if (!self::patch($buf, $off + $hdr, $off + $size, $delta)) return false;
            } elseif ($type === 'stco' || $type === 'co64') {
                $n = unpack('N', substr($buf, $off + $hdr + 4, 4))[1];
                $p = $off + $hdr + 8;
                for ($i = 0; $i < $n; $i++) {
                    if ($type === 'stco') {
                        $v = unpack('N', substr($buf, $p, 4))[1] + $delta;
                        if ($v > 0xFFFFFFFF) return false; // estouraria 32 bits → não arrisca
                        $buf = substr_replace($buf, pack('N', $v), $p, 4); $p += 4;
                    } else {
                        $v = self::u64(substr($buf, $p, 8)) + $delta;
                        $buf = substr_replace($buf, pack('NN', $v >> 32, $v & 0xFFFFFFFF), $p, 8); $p += 8;
                    }
                }
            }
            $off += $size;
        }
        return true;
    }

    public static function process(string $in, string $out): bool
    {
        $fileSize = filesize($in);
        $fh = fopen($in, 'rb');
        if (!$fh) return false;
        try {
            $atoms = self::topAtoms($fh, $fileSize);
            $types = array_column($atoms, 0);
            $iMoov = array_search('moov', $types, true);
            $iMdat = array_search('mdat', $types, true);
            if ($iMoov === false || $iMdat === false || $iMoov < $iMdat) return false; // já faststart

            [, $mOff, $mSize] = $atoms[$iMoov];
            fseek($fh, $mOff);
            $moov = fread($fh, $mSize);
            if (strlen($moov) !== $mSize) return false;
            // Tudo que estiver antes do mdat (ftyp, free…) fica na frente; o moov entra logo depois.
            // Os dados de mídia andam $mSize bytes para frente → offsets + $mSize.
            if (!self::patch($moov, 8, $mSize, $mSize)) return false;

            $o = fopen($out, 'wb');
            foreach ($atoms as $i => [$type, $off, $size]) {
                if ($i === $iMdat) fwrite($o, $moov);
                if ($i === $iMoov) continue;
                fseek($fh, $off);
                $left = $size;
                while ($left > 0) { $chunk = fread($fh, min(8 << 20, $left)); if ($chunk === '' || $chunk === false) break; fwrite($o, $chunk); $left -= strlen($chunk); }
            }
            fclose($o);
            return filesize($out) === $fileSize;
        } finally {
            fclose($fh);
        }
    }
}
