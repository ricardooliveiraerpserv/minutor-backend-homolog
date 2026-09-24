<?php

namespace App\SourceCode\Analyzer;

/**
 * Pré-processamento léxico de AdvPL/TL++ — a base robusta do parser (não é regex ingênua).
 * Varre o código char a char classificando regiões (código / comentário / string) e produz:
 *  - maskCode:        código com COMENTÁRIOS e STRINGS apagados (para extração estrutural
 *                     sem falso-positivo dentro de texto/comentário);
 *  - maskNoComments:  código com só os COMENTÁRIOS apagados (strings preservadas — necessário
 *                     para pegar nomes de tabela/param dentro de "SXX"/"MV_..." e includes);
 *  - strings:         lista de literais string {value,line} — para endpoints, SQL e secrets;
 *  - comments:        comentários {value,line}.
 * Offsets/linhas são preservados (regiões apagadas viram espaços, \n mantido).
 *
 * Regras léxicas cobertas: // linha · && linha (Clipper) · * no início da linha · /* bloco *​/
 * · strings "..." e '...' (sem escape, como AdvPL). Limitação conhecida: string [ ... ] não é
 * tratada como string (evita confundir com índice de array) — documentado.
 */
class AdvplLexer
{
    public string $code;
    public string $maskCode;
    public string $maskNoComments;
    /** @var list<array{value:string,line:int}> */
    public array $strings = [];
    /** @var list<array{value:string,line:int}> */
    public array $comments = [];

    public function __construct(string $code)
    {
        $this->code = str_replace(["\r\n", "\r"], "\n", $code);
        $this->tokenize();
    }

    private function tokenize(): void
    {
        $s = $this->code;
        $n = strlen($s);
        $mc = $s;   // maskCode
        $mn = $s;   // maskNoComments
        $i = 0;
        $line = 1;
        $atLineStart = true;

        $blank = function (string &$buf, int $from, int $to) use ($s): void {
            for ($k = $from; $k < $to; $k++) {
                if ($s[$k] !== "\n") {
                    $buf[$k] = ' ';
                }
            }
        };

        while ($i < $n) {
            $c = $s[$i];
            $c2 = $i + 1 < $n ? $s[$i + 1] : '';

            if ($c === "\n") {
                $i++;
                $line++;
                $atLineStart = true;
                continue;
            }

            $isLineComment = ($c === '/' && $c2 === '/') || ($c === '&' && $c2 === '&') || ($atLineStart && $c === '*');
            if ($isLineComment) {
                $j = $i;
                while ($j < $n && $s[$j] !== "\n") {
                    $j++;
                }
                $this->comments[] = ['value' => substr($s, $i, $j - $i), 'line' => $line];
                $blank($mc, $i, $j);
                $blank($mn, $i, $j);
                $i = $j;
                $atLineStart = false;
                continue;
            }

            if ($c === '/' && $c2 === '*') {
                $start = $i;
                $startLine = $line;
                $j = $i + 2;
                while ($j < $n && !($s[$j] === '*' && ($j + 1 < $n ? $s[$j + 1] : '') === '/')) {
                    if ($s[$j] === "\n") {
                        $line++;
                    }
                    $j++;
                }
                $j = min($n, $j + 2);
                $this->comments[] = ['value' => substr($s, $start, $j - $start), 'line' => $startLine];
                $blank($mc, $start, $j);
                $blank($mn, $start, $j);
                $i = $j;
                $atLineStart = false;
                continue;
            }

            if ($c === '"' || $c === "'") {
                $q = $c;
                $j = $i + 1;
                while ($j < $n && $s[$j] !== $q && $s[$j] !== "\n") {
                    $j++;
                }
                $this->strings[] = ['value' => substr($s, $i + 1, $j - $i - 1), 'line' => $line];
                $end = ($j < $n && $s[$j] === $q) ? $j + 1 : $j;   // inclui a aspa de fechamento
                $blank($mc, $i, $end);                              // maskCode: apaga a string toda
                // maskNoComments: preserva a string
                $i = $end;
                $atLineStart = false;
                continue;
            }

            if ($c !== ' ' && $c !== "\t") {
                $atLineStart = false;
            }
            $i++;
        }

        $this->maskCode = $mc;
        $this->maskNoComments = $mn;
    }

    /** Linha (1-indexed) de um offset. */
    public function lineAt(int $offset): int
    {
        return substr_count(substr($this->code, 0, max(0, $offset)), "\n") + 1;
    }
}
