<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Gera os ícones PNG da assinatura padrão (badges roxos com glifos brancos), em public/sig-icons/.
 * São estáticos — rode uma vez (ou após mudar a cor da marca). Embutidos via CID no e-mail.
 */
class GenerateSignatureIcons extends Command
{
    protected $signature = 'signature:gen-icons {--force : Regera também a faixa lets-do-it.png}';
    protected $description = 'Gera os ícones PNG da assinatura padrão ERPSERV';

    private const BRAND = [91, 33, 182];   // #5B21B6 (roxo sólido corporativo)
    private const SS = 4;                   // supersampling p/ antialias
    private const FONT = '/Library/Fonts/Arial Unicode.ttf';

    public function handle(): int
    {
        $dir = public_path('sig-icons');
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        // IMPORTANTE: por padrão NÃO sobrescreve assets já presentes (os ícones REAIS recortados da
        // identidade ERPSERV + a faixa oficial). Só desenha um placeholder p/ o que estiver faltando.
        // Use --force para regenerar tudo com os desenhos por código.
        $gen = function (string $name, callable $draw) use ($dir): void {
            $path = "$dir/$name.png";
            if (is_file($path) && !$this->option('force')) { $this->line("  mantido: $name.png"); return; }
            if (is_file($path)) $this->warn("  substituindo asset existente: $name.png (--force)");
            $draw($path);
        };

        $gen('phone',     fn ($p) => $this->circleGlyph($p, "\u{260E}"));   // ☎ (consistente)
        $gen('whatsapp',  fn ($p) => $this->circleGlyph($p, "\u{260E}"));   // ☎ (consistente)
        $gen('email',     fn ($p) => $this->circleGlyph($p, "\u{2709}"));
        $gen('web',       fn ($p) => $this->circleDraw($p, 'globe'));
        $gen('location',  fn ($p) => $this->circleDraw($p, 'pin'));
        $gen('instagram', fn ($p) => $this->squareDraw($p, 'instagram'));
        $gen('linkedin',  fn ($p) => $this->squareGlyph($p, 'in', 0.42));
        $gen('youtube',   fn ($p) => $this->squareDraw($p, 'play'));
        $gen('facebook',  fn ($p) => $this->squareGlyph($p, 'f', 0.6));
        // Faixa "LET'S DO IT": NUNCA sobrescreve o asset oficial (nem com --force); só desenha se faltar.
        if (!is_file("$dir/lets-do-it.png")) $this->letsDoIt("$dir/lets-do-it.png");

        $this->info('Ícones processados em public/sig-icons/');
        return self::SUCCESS;
    }

    /** Faixa decorativa "— LET'S DO IT —" (morse + texto). Asset oficial pode substituir o arquivo. */
    private function letsDoIt(string $path): void
    {
        $H = 56; $S = self::SS; $HS = $H * $S;
        $W = 560;                                   // largura de trabalho (cortada ao conteúdo)
        $im = $this->canvas2($W, $H);
        $purple = imagecolorallocate($im, 76, 42, 134);
        $light  = imagecolorallocate($im, 150, 120, 200);
        $gray   = imagecolorallocate($im, 150, 156, 163);
        $cy = (int) ($HS / 2);
        $bh = (int) ($HS * 0.15);                    // espessura do traço
        $x = (int) (4 * $S);

        // pílula horizontal
        $bar = function (int $w, int $col, int $yoff = 0) use ($im, &$x, $cy, $bh, $S) {
            $w *= $S;
            $this->roundedRect($im, $x, (int) ($cy - $bh / 2 + $yoff), $x + $w, (int) ($cy + $bh / 2 + $yoff), (int) ($bh / 2), $col);
            $x += $w;
        };
        $gap = function (int $g) use (&$x, $S) { $x += $g * $S; };

        // morse esquerda
        $bar(7, $purple); $gap(5); $bar(24, $purple); $gap(6); $bar(11, $light); $gap(6);
        $bar(7, $purple); $gap(5); $bar(28, $purple); $gap(6); $bar(11, $light); $gap(12);
        $bar(20, $gray);  $gap(12);

        // texto: "LET'S DO " (cinza) + "IT" (roxo, negrito)
        $font = $this->boldFont();
        $fs = (int) ($HS * 0.46);
        $x = $this->text($im, "LET'S DO ", $x, $cy, $fs, $gray, $font);
        $x = $this->text($im, 'IT', $x, $cy, $fs, $purple, $font);
        $gap(10);

        // morse direita
        $bar(20, $gray); $gap(10); $bar(7, $purple); $gap(5); $bar(26, $purple); $gap(6);
        $bar(11, $light); $gap(6); $bar(7, $purple);

        $cropW = (int) ceil(($x + 6 * $S) / $S);
        $this->saveRect($im, $path, $cropW, $H);
    }

    /** Escreve texto centralizado verticalmente em $cy; devolve o x final. */
    private function text(\GdImage $im, string $t, int $x, int $cy, int $fs, int $color, string $font): int
    {
        $bb = imagettfbbox($fs, 0, $font, $t);
        $y = $cy - ($bb[1] + $bb[7]) / 2;
        imagettftext($im, $fs, 0, $x, (int) $y, $color, $font, $t);
        return $x + ($bb[2] - $bb[0]);
    }

    private function boldFont(): string
    {
        foreach ([
            '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
            '/Library/Fonts/Arial Bold.ttf',
            '/System/Library/Fonts/Helvetica.ttc',
        ] as $f) { if (is_file($f)) return $f; }
        return self::FONT;
    }

    private function canvas2(int $w, int $h): \GdImage
    {
        $im = imagecreatetruecolor($w * self::SS, $h * self::SS);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagealphablending($im, true);
        imageantialias($im, true);
        return $im;
    }

    private function saveRect(\GdImage $im, string $path, int $w, int $h): void
    {
        $out = imagecreatetruecolor($w, $h);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagecopyresampled($out, $im, 0, 0, 0, 0, $w, $h, $w * self::SS, $h * self::SS);
        imagepng($out, $path);
        imagedestroy($im);
        imagedestroy($out);
    }

    /** Cria uma tela RGBA transparente em alta resolução. */
    private function canvas(int $size): \GdImage
    {
        $S = $size * self::SS;
        $im = imagecreatetruecolor($S, $S);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagealphablending($im, true);
        imageantialias($im, true);
        return $im;
    }

    private function save(\GdImage $im, string $path, int $size): void
    {
        $out = imagecreatetruecolor($size, $size);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        $S = $size * self::SS;
        imagecopyresampled($out, $im, 0, 0, 0, 0, $size, $size, $S, $S);
        imagepng($out, $path);
        imagedestroy($im);
        imagedestroy($out);
    }

    private function brandColor(\GdImage $im): int
    {
        return imagecolorallocate($im, ...self::BRAND);
    }

    /** Badge circular + glifo branco centralizado. */
    private function circleGlyph(string $path, string $char, int $size = 90): void
    {
        $im = $this->canvas($size);
        $S = $size * self::SS;
        imagefilledellipse($im, $S / 2, $S / 2, $S, $S, $this->brandColor($im));
        $this->centerGlyph($im, $char, $S, (int) ($S * 0.46));
        $this->save($im, $path, $size);
    }

    /** Badge circular + desenho vetorial (globo). */
    private function circleDraw(string $path, string $kind, int $size = 90): void
    {
        $im = $this->canvas($size);
        $S = $size * self::SS;
        imagefilledellipse($im, $S / 2, $S / 2, $S, $S, $this->brandColor($im));
        $white = imagecolorallocate($im, 255, 255, 255);
        if ($kind === 'globe') {
            // Preencher-e-subtrair (anéis limpos, sem o GD bugar espessura de elipse).
            $brand = $this->brandColor($im);
            $c = (int) ($S / 2); $R = (int) ($S * 0.30); $th = max(3, (int) ($S * 0.05));
            // anel externo (contorno do globo)
            imagefilledellipse($im, $c, $c, $R * 2, $R * 2, $white);
            imagefilledellipse($im, $c, $c, ($R - $th) * 2, ($R - $th) * 2, $brand);
            // meridiano: elipse vertical estreita em anel → 2 curvas
            $mw = (int) ($S * 0.155);
            imagefilledellipse($im, $c, $c, $mw * 2, ($R - 1) * 2, $white);
            imagefilledellipse($im, $c, $c, max(1, $mw - $th) * 2, ($R - 1 - $th) * 2, $brand);
            // equador: linha horizontal fina
            imagefilledrectangle($im, $c - $R + $th, (int) ($c - $th / 2), $c + $R - $th, (int) ($c + $th / 2), $white);
        } elseif ($kind === 'pin') {
            $c = $S / 2; $hr = (int) ($S * 0.18); $hy = (int) ($S * 0.40);
            imagefilledellipse($im, $c, $hy, $hr * 2, $hr * 2, $white);                 // cabeça
            imagefilledpolygon($im, [$c - (int) ($hr * 0.82), $hy + (int) ($hr * 0.5), $c + (int) ($hr * 0.82), $hy + (int) ($hr * 0.5), $c, (int) ($S * 0.72)], $white); // ponta
            imagefilledellipse($im, $c, $hy, (int) ($hr * 0.85), (int) ($hr * 0.85), $this->brandColor($im)); // furo
        }
        $this->save($im, $path, $size);
    }

    /** Badge quadrado arredondado + glifo branco. */
    private function squareGlyph(string $path, string $text, float $scale, int $size = 84): void
    {
        $im = $this->canvas($size);
        $S = $size * self::SS;
        $this->roundedRect($im, 0, 0, $S - 1, $S - 1, (int) ($S * 0.24), $this->brandColor($im));
        $this->centerGlyph($im, $text, $S, (int) ($S * $scale));
        $this->save($im, $path, $size);
    }

    /** Badge quadrado arredondado + desenho (instagram/play). */
    private function squareDraw(string $path, string $kind, int $size = 84): void
    {
        $im = $this->canvas($size);
        $S = $size * self::SS;
        $this->roundedRect($im, 0, 0, $S - 1, $S - 1, (int) ($S * 0.24), $this->brandColor($im));
        $white = imagecolorallocate($im, 255, 255, 255);
        if ($kind === 'instagram') {
            // Técnica preencher-e-subtrair (sem arcos → cantos limpos).
            $brand = $this->brandColor($im);
            $m = (int) ($S * 0.22); $th = max(3, (int) ($S * 0.06)); $r = (int) ($S * 0.22);
            $this->roundedRect($im, $m, $m, $S - $m, $S - $m, $r, $white);                              // moldura (branca)
            $this->roundedRect($im, $m + $th, $m + $th, $S - $m - $th, $S - $m - $th, max(1, $r - $th), $brand); // furo roxo → vira frame
            $c = (int) ($S / 2); $lr = (int) ($S * 0.18); $lth = max(3, (int) ($S * 0.06));
            imagefilledellipse($im, $c, $c, $lr * 2, $lr * 2, $white);                                  // lente (disco branco)
            imagefilledellipse($im, $c, $c, ($lr - $lth) * 2, ($lr - $lth) * 2, $brand);                // furo roxo → vira anel
            $fd = max(4, (int) ($S * 0.085));
            imagefilledellipse($im, (int) ($S * 0.655), (int) ($S * 0.345), $fd, $fd, $white);          // flash
        } elseif ($kind === 'play') {
            $x = (int) ($S * 0.38); $y1 = (int) ($S * 0.34); $y2 = (int) ($S * 0.66); $x2 = (int) ($S * 0.66);
            imagefilledpolygon($im, [$x, $y1, $x, $y2, $x2, (int) ($S / 2)], $white);
        }
        $this->save($im, $path, $size);
    }

    private function centerGlyph(\GdImage $im, string $char, int $S, int $fontSize): void
    {
        $white = imagecolorallocate($im, 255, 255, 255);
        $bb = imagettfbbox($fontSize, 0, self::FONT, $char);
        $gw = $bb[2] - $bb[0]; $gh = $bb[1] - $bb[7];
        $x = (int) (($S - $gw) / 2 - $bb[0]);
        $y = (int) (($S - $gh) / 2 - $bb[7]);
        imagettftext($im, $fontSize, 0, $x, $y, $white, self::FONT, $char);
    }

    private function roundedRect(\GdImage $im, int $x1, int $y1, int $x2, int $y2, int $r, int $color): void
    {
        imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $color);
        imagefilledrectangle($im, $x1, $y1 + $r, $x2, $y2 - $r, $color);
        imagefilledellipse($im, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($im, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($im, $x1 + $r, $y2 - $r, $r * 2, $r * 2, $color);
        imagefilledellipse($im, $x2 - $r, $y2 - $r, $r * 2, $r * 2, $color);
    }

    private function roundedRectOutline(\GdImage $im, int $x1, int $y1, int $x2, int $y2, int $r, int $color, int $th): void
    {
        imagesetthickness($im, $th);
        imageline($im, $x1 + $r, $y1, $x2 - $r, $y1, $color);
        imageline($im, $x1 + $r, $y2, $x2 - $r, $y2, $color);
        imageline($im, $x1, $y1 + $r, $x1, $y2 - $r, $color);
        imageline($im, $x2, $y1 + $r, $x2, $y2 - $r, $color);
        imagearc($im, $x1 + $r, $y1 + $r, $r * 2, $r * 2, 180, 270, $color);
        imagearc($im, $x2 - $r, $y1 + $r, $r * 2, $r * 2, 270, 360, $color);
        imagearc($im, $x1 + $r, $y2 - $r, $r * 2, $r * 2, 90, 180, $color);
        imagearc($im, $x2 - $r, $y2 - $r, $r * 2, $r * 2, 0, 90, $color);
    }
}
