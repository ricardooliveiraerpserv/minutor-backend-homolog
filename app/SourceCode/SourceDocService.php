<?php

namespace App\SourceCode;

use App\Models\HelpDeskTicket;
use App\Models\SourceDoc;
use App\Models\SourceDocChange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpWord\TemplateProcessor;

/**
 * Gera/atualiza a documentação de um fonte na GMUD: a IA (Anthropic) analisa o código (e o diff
 * vs. versão anterior) e o serviço preenche o timbrado ERPSERV (template com placeholders),
 * mantendo um HISTÓRICO de alterações que cresce a cada GMUD. Degrada com graça sem IA.
 */
class SourceDocService
{
    private const TEMPLATE = 'resources/templates/source_doc_template.docx';
    private const MAX_CODE_CHARS = 24000; // corta fontes gigantes p/ caber no contexto/custo

    public function aiConfigured(): bool
    {
        return (bool) config('services.anthropic.api_key');
    }

    /**
     * Analisa + persiste (upsert do SourceDoc + nova entrada no histórico) + renderiza o .docx.
     * @return string bytes do .docx
     */
    public function generate(HelpDeskTicket $ticket, string $owner, string $repository, string $path, ?int $customerId, string $newCode, ?string $oldCode, string $responsavel): string
    {
        $ai = $this->analyze($path, $newCode, $oldCode);

        $doc = SourceDoc::firstOrNew(['owner' => $owner, 'repository' => $repository, 'path' => $path]);
        $doc->customer_id = $customerId;
        $doc->objetivo = $ai['objetivo'];
        $doc->estrutura = $ai['estrutura'];
        $doc->tabelas = $ai['tabelas'];
        $doc->save();

        $doc->changes()->create([
            'ticket_id'     => $ticket->id,
            'ticket_number' => $ticket->ticket_number ?: ('#' . $ticket->id),
            'responsavel'   => $responsavel,
            'resumo'        => $ai['resumo'],
        ]);

        return $this->render($doc->fresh('changes'), $owner, $repository, $path, $responsavel);
    }

    /** Chama a IA; sem chave/erro → fallback mecânico (extrai funções por regex). */
    private function analyze(string $path, string $newCode, ?string $oldCode): array
    {
        $name = basename($path);
        if (!$this->aiConfigured()) {
            return [
                'objetivo'  => 'Descrição automática indisponível (IA não configurada no servidor).',
                'estrutura' => $this->extractFunctions($newCode) ?: '—',
                'tabelas'   => '—',
                'resumo'    => $oldCode === null ? 'Criação inicial do fonte.' : 'Fonte alterado nesta GMUD.',
            ];
        }

        $sys = 'Você é um analista de sistemas Protheus/AdvPL/TL++. Recebe um fonte e devolve EXCLUSIVAMENTE um JSON '
            . 'válido (sem markdown, sem cercas), em português do Brasil, com EXATAMENTE estas chaves, cada uma um '
            . 'PARÁGRAFO ÚNICO sem quebras de linha (use "; " para listar): '
            . '"objetivo" (o que o programa faz), '
            . '"estrutura" (funções User/Static Function e seus parâmetros), '
            . '"tabelas" (tabelas e campos do dicionário usados), '
            . '"resumo" (se houver versão ANTERIOR, descreva objetivamente o que MUDOU entre elas — impacto; se não houver, escreva "Criação inicial do fonte."). '
            . 'Não invente o que não estiver no código.';

        $user = "Arquivo: {$name}\n\n=== VERSÃO NOVA ===\n" . $this->clip($newCode);
        if ($oldCode !== null) {
            $user .= "\n\n=== VERSÃO ANTERIOR (no repositório) ===\n" . $this->clip($oldCode);
        }

        try {
            $res = Http::timeout(90)->withHeaders([
                'x-api-key'         => config('services.anthropic.api_key'),
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])->post(rtrim((string) config('services.anthropic.base_url', 'https://api.anthropic.com/v1'), '/') . '/messages', [
                'model'      => config('services.anthropic.model', 'claude-sonnet-5'),
                'max_tokens' => 1500,
                'system'     => $sys,
                'messages'   => [['role' => 'user', 'content' => $user]],
            ]);
            if (!$res->successful()) {
                Log::warning('source_doc.ai_http', ['status' => $res->status()]);
                return $this->aiFallback($newCode, $oldCode);
            }
            $text = (string) ($res->json('content.0.text') ?? '');
            $json = $this->parseJson($text);
            if (!$json) {
                return $this->aiFallback($newCode, $oldCode);
            }
            return [
                'objetivo'  => $this->oneLine($json['objetivo'] ?? '—'),
                'estrutura' => $this->oneLine($json['estrutura'] ?? '—'),
                'tabelas'   => $this->oneLine($json['tabelas'] ?? '—'),
                'resumo'    => $this->oneLine($json['resumo'] ?? ($oldCode === null ? 'Criação inicial do fonte.' : 'Fonte alterado.')),
            ];
        } catch (\Throwable $e) {
            Log::warning('source_doc.ai_error', ['error' => $e->getMessage()]);
            return $this->aiFallback($newCode, $oldCode);
        }
    }

    private function aiFallback(string $newCode, ?string $oldCode): array
    {
        return [
            'objetivo'  => 'Descrição automática indisponível no momento (falha ao consultar a IA).',
            'estrutura' => $this->extractFunctions($newCode) ?: '—',
            'tabelas'   => '—',
            'resumo'    => $oldCode === null ? 'Criação inicial do fonte.' : 'Fonte alterado nesta GMUD.',
        ];
    }

    /** Renderiza o .docx no timbrado a partir do SourceDoc + histórico. */
    private function render(SourceDoc $doc, string $owner, string $repository, string $path, string $responsavel): string
    {
        $tpl = new TemplateProcessor(base_path(self::TEMPLATE));
        $tpl->setValue('FONTE', $this->esc(basename($path)));
        $tpl->setValue('CLIENTE', $this->esc(optional($doc->customer_id ? \App\Models\Customer::find($doc->customer_id) : null)->name ?: '—'));
        $tpl->setValue('CHAMADO', $this->esc(optional($doc->changes->last())->ticket_number ?: '—'));
        $tpl->setValue('RESPONSAVEL', $this->esc($responsavel));
        $tpl->setValue('DATA', Carbon::now()->format('d/m/Y H:i'));
        $tpl->setValue('REPO', $this->esc("{$owner}/{$repository} · {$path}"));
        $tpl->setValue('OBJETIVO', $this->esc($doc->objetivo ?: '—'));
        $tpl->setValue('ESTRUTURA', $this->esc($doc->estrutura ?: '—'));
        $tpl->setValue('TABELAS', $this->esc($doc->tabelas ?: '—'));
        $tpl->setValue('RESUMO', $this->esc(optional($doc->changes->last())->resumo ?: '—'));

        $changes = $doc->changes->values();
        $tpl->cloneRow('h_chamado', max(1, $changes->count()));
        if ($changes->isEmpty()) {
            $tpl->setValue('h_chamado#1', '—');
            $tpl->setValue('h_data#1', '—');
            $tpl->setValue('h_resp#1', '—');
            $tpl->setValue('h_resumo#1', '—');
        } else {
            foreach ($changes as $i => $c) {
                $n = $i + 1;
                $tpl->setValue("h_chamado#{$n}", $this->esc($c->ticket_number ?: '—'));
                $tpl->setValue("h_data#{$n}", optional($c->created_at)->format('d/m/Y H:i') ?: '—');
                $tpl->setValue("h_resp#{$n}", $this->esc($c->responsavel ?: '—'));
                $tpl->setValue("h_resumo#{$n}", $this->esc($c->resumo ?: '—'));
            }
        }

        $tmp = tempnam(sys_get_temp_dir(), 'srcdoc') . '.docx';
        $tpl->saveAs($tmp);
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $bytes;
    }

    /** Extrai nomes de funções AdvPL por regex (fallback sem IA). */
    private function extractFunctions(string $code): string
    {
        preg_match_all('/\b(User|Static)\s+Function\s+([A-Za-z0-9_]+)\s*\(([^)]*)\)/i', $code, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $f) {
            $params = trim($f[3]) !== '' ? "({$f[3]})" : '()';
            $out[] = "{$f[1]} Function {$f[2]}{$params}";
        }
        return implode('; ', array_slice($out, 0, 40));
    }

    private function clip(string $s): string
    {
        return mb_strlen($s) > self::MAX_CODE_CHARS ? mb_substr($s, 0, self::MAX_CODE_CHARS) . "\n[…truncado…]" : $s;
    }

    private function oneLine(string $s): string
    {
        return trim(preg_replace('/\s*\R\s*/u', '; ', $s)) ?: '—';
    }

    /** setValue do PhpWord escapa XML por padrão? Não — escapamos aqui p/ segurança. */
    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function parseJson(string $text): ?array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?|```$/m', '', $text);
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $json = json_decode(substr($text, $start, $end - $start + 1), true);
        return is_array($json) ? $json : null;
    }
}
