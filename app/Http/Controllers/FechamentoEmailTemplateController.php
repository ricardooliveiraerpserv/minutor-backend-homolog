<?php

namespace App\Http\Controllers;

use App\Models\FechamentoEmailTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FechamentoEmailTemplateController extends Controller
{
    private function authorizeAdmin(Request $request): ?JsonResponse
    {
        $u = $request->user();
        if (!$u->isAdmin() && !$u->isAdministrativo()) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }
        return null;
    }

    public function index(Request $request): JsonResponse
    {
        if ($deny = $this->authorizeAdmin($request)) return $deny;

        $items = FechamentoEmailTemplate::query()
            ->orderBy('categoria')
            ->orderBy('contract_type')
            ->orderByDesc('active')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($deny = $this->authorizeAdmin($request)) return $deny;

        $data = $this->validateData($request);
        $tpl  = FechamentoEmailTemplate::create($data);
        if ($tpl->active) {
            $this->deactivateSiblings($tpl);
        }

        return response()->json(['data' => $tpl], 201);
    }

    public function update(Request $request, FechamentoEmailTemplate $template): JsonResponse
    {
        if ($deny = $this->authorizeAdmin($request)) return $deny;

        $data = $this->validateData($request, $template);
        $template->update($data);
        if ($template->active) {
            $this->deactivateSiblings($template);
        }

        return response()->json(['data' => $template]);
    }

    public function destroy(Request $request, FechamentoEmailTemplate $template): JsonResponse
    {
        if ($deny = $this->authorizeAdmin($request)) return $deny;

        $template->delete();
        return response()->json(['success' => true]);
    }

    /**
     * Prévia do e-mail com o MESMO layout do envio real (Blade), preenchida com
     * valores de exemplo. Renderiza o assunto/corpo em edição (não o salvo no banco).
     * POST /fechamento-email-templates/preview  { categoria, subject, body, empresa? }
     */
    public function preview(Request $request): JsonResponse
    {
        if ($deny = $this->authorizeAdmin($request)) return $deny;

        $data = $request->validate([
            'categoria' => ['required', Rule::in(FechamentoEmailTemplate::CATEGORIAS)],
            'subject'   => ['nullable', 'string'],
            'body'      => ['nullable', 'string'],
            'empresa'   => ['nullable', Rule::in(FechamentoEmailTemplate::EMPRESAS)],
        ]);

        $categoria = $data['categoria'];
        $isBizify  = $categoria === 'consultor' && ($data['empresa'] ?? null) === 'bizify';
        $vars      = $this->sampleVars($categoria, $isBizify);

        $subject   = $this->fillVars((string) ($data['subject'] ?? ''), $vars);
        $mensagem  = $this->fillVars((string) ($data['body'] ?? ''), $vars);
        $senderName = $request->user()->name ?? 'ERPSERV Consultoria';

        $view = 'emails.fechamento.' . match ($categoria) {
            'parceiro'  => 'parceiro',
            'cliente'   => 'cliente',
            'excedente' => 'excedente',
            default     => 'consultor',
        };
        $common = [
            'periodo'         => $vars['periodo'],
            'valorTotal'      => $categoria === 'excedente' ? $vars['valor_total'] : $vars['valor'],
            'mensagem'        => $mensagem,
            'senderName'      => $senderName,
            'withAttachments' => true,
            'mode'            => $categoria === 'cliente' ? 'servicos' : 'ambos',
        ];
        $viewData = match ($categoria) {
            'parceiro'  => $common + ['parceiroName' => $vars['nome']],
            'excedente' => $common + ['clienteName' => $vars['nome']],
            'cliente'   => $common + [
                'clienteName' => $vars['nome'],
                'projetos'    => [['codigo' => 'PRJ-001', 'nome' => 'Implantação ERP']],
                'temDesconto' => false, 'subtotalFmt' => $vars['valor'], 'descontoFmt' => 'R$ 0,00', 'descontoDescricao' => '',
            ],
            default     => $common + ['consultantName' => $vars['nome'], 'isBizify' => $isBizify, 'isContinuation' => false, 'bodyText' => null],
        };

        $html = view($view, $viewData)->render();
        // Prévia: força o logo claro a aparecer no card branco (o swap dark-mode mostraria o branco, invisível aqui).
        $override = '<style>.erp-light{display:inline-block !important}.erp-dark{display:none !important}</style>';
        $html = str_ireplace('</head>', $override . '</head>', $html);

        return response()->json(['html' => $html, 'subject' => $subject]);
    }

    /** Valores de exemplo p/ a prévia (espelham os do front). */
    private function sampleVars(string $categoria, bool $isBizify): array
    {
        $nome = in_array($categoria, ['cliente', 'excedente'], true) ? 'ACME Comércio Ltda' : 'João da Silva';
        return [
            'nome' => $nome, 'periodo' => 'Julho de 2026', 'valor' => 'R$ 5.000,00', 'data' => '05/08/2026',
            'empresa' => $isBizify ? 'Bizify' : 'ERPSERV', 'razao_social' => 'ACME Comércio Ltda',
            'competencia' => '07/2026', 'horas_contratadas' => '160,00h', 'horas_consumidas' => '180,00h',
            'horas_excedentes' => '20,00h', 'valor_hora' => 'R$ 150,00', 'valor_total' => 'R$ 3.000,00',
        ];
    }

    private function fillVars(string $text, array $vars): string
    {
        foreach ($vars as $k => $v) {
            $text = str_replace('{' . $k . '}', (string) $v, $text);
        }
        return $text;
    }

    private function validateData(Request $request, ?FechamentoEmailTemplate $existing = null): array
    {
        $data = $request->validate([
            'categoria'     => ['required', Rule::in(FechamentoEmailTemplate::CATEGORIAS)],
            'contract_type' => ['nullable', Rule::in(FechamentoEmailTemplate::CONTRACT_TYPES)],
            'empresa'       => ['nullable', Rule::in(FechamentoEmailTemplate::EMPRESAS)],
            'nome'          => ['nullable', 'string', 'max:255'],
            'subject'       => ['required', 'string', 'max:255'],
            'body'          => ['required', 'string'],
            'pay_day'       => ['nullable', 'integer', 'min:1', 'max:31'],
            'active'        => ['nullable', 'boolean'],
        ]);

        $categoria = $data['categoria'] ?? $existing?->categoria;
        // cliente e excedente são únicos (sem tipo de contrato); consultor/parceiro exigem o tipo.
        if (in_array($categoria, ['cliente', 'excedente'], true)) {
            $data['contract_type'] = null;
        } elseif (empty($data['contract_type'])) {
            abort(response()->json([
                'message' => 'Selecione o tipo de contrato (cooperado/clt/pj).',
                'errors'  => ['contract_type' => ['Obrigatório para consultor/parceiro.']],
            ], 422));
        }
        // Empresa Bizify só faz sentido p/ consultor; demais → erpserv.
        $data['empresa'] = ($categoria === 'consultor' && ($data['empresa'] ?? null) === 'bizify') ? 'bizify' : 'erpserv';
        $data['active'] = (bool) ($data['active'] ?? false);

        return $data;
    }

    /** Garante só 1 ativo por (categoria, contract_type, empresa). */
    private function deactivateSiblings(FechamentoEmailTemplate $tpl): void
    {
        FechamentoEmailTemplate::where('categoria', $tpl->categoria)
            ->where('empresa', $tpl->empresa)
            ->where(function ($q) use ($tpl) {
                $tpl->contract_type === null
                    ? $q->whereNull('contract_type')
                    : $q->where('contract_type', $tpl->contract_type);
            })
            ->where('id', '!=', $tpl->id)
            ->update(['active' => false]);
    }
}
