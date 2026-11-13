<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\StageAllocation;
use App\Models\StageDelivery;
use App\Models\User;
use App\Services\CronogramaTemplateService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlsDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cronograma ⇄ Excel (layout próprio do Minutor).
 *
 *  - EXPORTAR: baixa o cronograma do projeto no layout (vazio = só o cabeçalho), com as abas
 *    "Instruções" e "Usuários" (e-mails válidos). Serve de modelo para montar um cronograma novo.
 *  - IMPORTAR: lê o layout, valida LINHA A LINHA (nada é criado se houver erro) e cria as
 *    etapas / sub-etapas / atividades no projeto (ADICIONA ao que já existe), opcionalmente
 *    reencaixando as datas a partir de um novo início.
 *
 * Usuários são identificados pelo E-MAIL (chave única), nunca pelo nome.
 * Regra do cronograma: 1 consultor por atividade — o "Responsável" da atividade vira o
 * consultor alocado (primary) com as horas da atividade e entra em Consultores do projeto.
 */
class CronogramaLayoutController extends Controller
{
    /** Colunas do layout (ordem fixa). */
    private const COLS = [
        'tipo'        => 'Tipo',
        'codigo'      => 'Código',
        'nome'        => 'Nome',
        'responsavel' => 'Responsável (e-mail)',
        'inicio'      => 'Início',
        'fim'         => 'Fim',
        'horas'       => 'Horas planejadas',
        'prioridade'  => 'Prioridade',
        'depende'     => 'Depende de (código)',
        'cliente'     => 'Cliente envolvido',
        'cliente_email' => 'E-mail do cliente',
        'descricao'   => 'Descrição',
    ];

    private const PRIO_OUT = ['low' => 'Baixa', 'medium' => 'Média', 'high' => 'Alta'];

    public function __construct(private CronogramaTemplateService $service) {}

    /* ───────────────────────────── EXPORTAR ───────────────────────────── */

    public function export(Project $project): StreamedResponse
    {
        $stages = $project->stages()
            ->with(['deliveries' => fn ($q) => $q->orderBy('order_index')])
            ->orderBy('order_index')->get();
        $emails = User::whereIn('id', $stages->pluck('responsible_user_id')
                ->merge($stages->flatMap(fn ($s) => $s->deliveries->pluck('responsible_user_id')))->filter()->unique())
            ->pluck('email', 'id');

        // Linhas na ordem do cronograma: etapa → atividades → sub-etapas (→ atividades).
        $rows = [];
        $codeOf = []; // delivery_id → código (p/ "Depende de")
        $top = $stages->whereNull('parent_stage_id')->sortBy('order_index')->values();
        $emitDeliveries = function ($stage, string $base, int &$n) use (&$rows, &$codeOf, $emails) {
            foreach ($stage->deliveries as $d) {
                $code = $base . '.' . (++$n);
                $codeOf[$d->id] = $code;
                $rows[] = ['Atividade', $code, $d->title, $emails[$d->responsible_user_id] ?? '',
                    $d->planned_start_at, $d->due_date, (float) ($d->hours_planned ?? 0),
                    self::PRIO_OUT[$d->priority] ?? 'Média', '__dep:' . ($d->depends_on_delivery_id ?? ''),
                    $d->client_involved ? 'Sim' : 'Não', $d->client_email ?? '', (string) ($d->description ?? '')];
            }
        };
        foreach ($top as $i => $etapa) {
            $code = (string) ($i + 1);
            $rows[] = ['Etapa', $code, $etapa->name, $emails[$etapa->responsible_user_id] ?? '',
                $etapa->stage_start_at, $etapa->expected_end_date, (float) ($etapa->hours_planned ?? 0), '', '', '', '', ''];
            $n = 0;
            $emitDeliveries($etapa, $code, $n);
            foreach ($stages->where('parent_stage_id', $etapa->id)->sortBy('order_index') as $sub) {
                $subCode = $code . '.' . (++$n);
                $rows[] = ['Sub-etapa', $subCode, $sub->name, $emails[$sub->responsible_user_id] ?? '',
                    $sub->stage_start_at, $sub->expected_end_date, (float) ($sub->hours_planned ?? 0), '', '', '', '', ''];
                $m = 0;
                $emitDeliveries($sub, $subCode, $m);
            }
        }
        // Resolve "Depende de" para o código (as predecessoras podem vir depois na lista).
        foreach ($rows as &$r) {
            if (is_string($r[8]) && str_starts_with($r[8], '__dep:')) {
                $id = (int) substr($r[8], 6);
                $r[8] = $id ? ($codeOf[$id] ?? '') : '';
            }
        }
        unset($r);

        $book = new Spreadsheet();
        $sh = $book->getActiveSheet();
        $sh->setTitle('Cronograma');
        $this->writeTable($sh, array_values(self::COLS), $rows);

        $this->writeInstructions($book->createSheet());
        $this->writeUsers($book->createSheet());
        $book->setActiveSheetIndex(0);

        $slug = trim(preg_replace('/[^A-Za-z0-9]+/', '_', (string) ($project->code ?: $project->name)), '_') ?: 'projeto';
        $filename = "cronograma_{$slug}.xlsx";

        return response()->streamDownload(function () use ($book) {
            (new Xlsx($book))->save('php://output');
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    private function writeTable($sh, array $headers, array $rows): void
    {
        foreach ($headers as $c => $h) {
            $sh->setCellValue(Coordinate::stringFromColumnIndex($c + 1) . '1', $h);
        }
        $last = Coordinate::stringFromColumnIndex(count($headers));
        $sh->getStyle("A1:{$last}1")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sh->getStyle("A1:{$last}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('4A2583');
        $sh->freezePane('A2');
        foreach ($rows as $i => $r) {
            foreach ($r as $c => $v) {
                $cell = Coordinate::stringFromColumnIndex($c + 1) . ($i + 2);
                if ($v instanceof \DateTimeInterface || ($c === 4 || $c === 5) && $v) {
                    $sh->setCellValue($cell, Carbon::parse($v)->format('d/m/Y'));
                } else {
                    $sh->setCellValueExplicit($cell, (string) $v, is_float($v) || is_int($v)
                        ? \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC
                        : \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                }
            }
        }
        foreach (range(1, count($headers)) as $c) {
            $sh->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }
    }

    private function writeInstructions($sh): void
    {
        $sh->setTitle('Instruções');
        $lines = [
            ['LAYOUT DO CRONOGRAMA — MINUTOR', ''],
            ['', ''],
            ['Coluna', 'Como preencher'],
            ['Tipo', 'Etapa, Sub-etapa ou Atividade. A Sub-etapa pertence à última Etapa acima dela; a Atividade, à última Etapa ou Sub-etapa acima dela.'],
            ['Código', 'Identificador da linha (ex.: 1, 1.1, 1.2.1). Use-o na coluna "Depende de". Não pode repetir.'],
            ['Nome', 'Obrigatório. Nome da etapa/sub-etapa ou título da atividade.'],
            ['Responsável (e-mail)', 'E-MAIL do usuário no Minutor (não o nome). Na Atividade, ele é o consultor alocado, com as horas da atividade. Veja a aba "Usuários".'],
            ['Início / Fim', 'Datas no formato dd/mm/aaaa. O Fim não pode ser antes do Início.'],
            ['Horas planejadas', 'Número (ex.: 8 ou 12,5).'],
            ['Prioridade', 'Só para Atividade: Baixa, Média ou Alta (vazio = Média).'],
            ['Depende de (código)', 'Só para Atividade: o Código da atividade predecessora (término → início).'],
            ['Cliente envolvido', 'Só para Atividade: Sim ou Não. Com Sim, informe o E-mail do cliente que aprova.'],
            ['E-mail do cliente', 'E-mail do cliente envolvido na atividade.'],
            ['Descrição', 'Opcional. Detalhe da atividade.'],
            ['', ''],
            ['Importar', 'No cronograma do projeto: Modelos › Excel (layout) › Importar. O sistema confere linha a linha e NÃO cria nada se houver erro. As etapas são ADICIONADAS ao cronograma atual.'],
            ['Novo início', 'Opcional na importação: informe uma nova data de início e todas as datas são deslocadas a partir dela, mantendo os intervalos.'],
            ['', ''],
            ['EXEMPLO', ''],
        ];
        foreach ($lines as $i => [$a, $b]) {
            $sh->setCellValue('A' . ($i + 1), $a);
            $sh->setCellValue('B' . ($i + 1), $b);
        }
        $sh->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sh->getStyle('A3:B3')->getFont()->setBold(true);
        $sh->getStyle('A19')->getFont()->setBold(true);
        $ex = [
            array_values(self::COLS),
            ['Etapa', '1', 'Levantamento', 'coordenador@empresa.com.br', '03/11/2026', '14/11/2026', '40', '', '', '', '', ''],
            ['Atividade', '1.1', 'Reunião de kickoff', 'consultor@empresa.com.br', '03/11/2026', '03/11/2026', '4', 'Alta', '', 'Não', '', 'Alinhamento com o cliente'],
            ['Atividade', '1.2', 'Mapeamento dos processos', 'consultor@empresa.com.br', '04/11/2026', '14/11/2026', '36', 'Média', '1.1', 'Sim', 'gestor@cliente.com.br', ''],
            ['Sub-etapa', '1.3', 'Validação', '', '17/11/2026', '18/11/2026', '8', '', '', '', '', ''],
            ['Atividade', '1.3.1', 'Aprovação do levantamento', 'consultor@empresa.com.br', '17/11/2026', '18/11/2026', '8', 'Média', '1.2', 'Sim', 'gestor@cliente.com.br', ''],
        ];
        foreach ($ex as $i => $r) {
            foreach ($r as $c => $v) $sh->setCellValue(Coordinate::stringFromColumnIndex($c + 1) . (20 + $i), $v);
        }
        $sh->getStyle('A20:L20')->getFont()->setBold(true);
        $sh->getColumnDimension('A')->setWidth(24);
        $sh->getColumnDimension('B')->setWidth(110);
    }

    private function writeUsers($sh): void
    {
        $sh->setTitle('Usuários');
        $users = User::query()->where('enabled', true)->where('type', '!=', 'cliente')
            ->orderBy('name')->get(['name', 'email', 'type']);
        $rows = $users->map(fn ($u) => [$u->name, $u->email, $u->type])->all();
        $this->writeTable($sh, ['Nome', 'E-mail (use este no layout)', 'Perfil'], $rows);
    }

    /* ───────────────────────────── IMPORTAR ───────────────────────────── */

    public function import(Request $request, Project $project): JsonResponse
    {
        $request->validate([
            'file'       => 'required|file|mimes:xlsx,xls|max:5120',
            'start_date' => 'nullable|date',
        ]);

        try {
            $book = IOFactory::load($request->file('file')->getRealPath());
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Não foi possível ler o arquivo. Use o layout exportado pelo Minutor (.xlsx).'], 422);
        }
        $sh = $book->getSheetByName('Cronograma') ?? $book->getSheet(0);
        $raw = $sh->toArray(null, false, false, false);
        if (count($raw) < 2) {
            return response()->json(['message' => 'O layout está vazio: preencha as linhas abaixo do cabeçalho.'], 422);
        }

        // Cabeçalho → índice de coluna (aceita a ordem que vier, pelo nome).
        $norm = fn ($s) => mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $s)));
        $head = array_map($norm, $raw[0]);
        $idx = [];
        foreach (self::COLS as $key => $label) {
            $pos = array_search($norm($label), $head, true);
            if ($pos === false && in_array($key, ['tipo', 'nome'], true)) {
                return response()->json(['message' => "Coluna \"{$label}\" não encontrada. Use o layout exportado pelo Minutor."], 422);
            }
            $idx[$key] = $pos === false ? null : $pos;
        }
        $get = fn (array $r, string $k) => $idx[$k] === null ? null : ($r[$idx[$k]] ?? null);

        // Usuários por e-mail (chave única).
        $userByEmail = User::query()->where('enabled', true)->get(['id', 'email', 'type'])
            ->keyBy(fn ($u) => mb_strtolower(trim((string) $u->email)));

        $errors = [];
        $items = []; // linhas válidas, na ordem
        $codes = [];
        foreach (array_slice($raw, 1) as $i => $r) {
            $line = $i + 2;
            if (!array_filter($r, fn ($v) => trim((string) $v) !== '')) continue; // linha vazia

            $tipoRaw = $norm($get($r, 'tipo'));
            $tipo = match (true) {
                $tipoRaw === 'etapa' => 'stage',
                in_array($tipoRaw, ['sub-etapa', 'subetapa', 'sub etapa'], true) => 'sub',
                $tipoRaw === 'atividade' => 'activity',
                default => null,
            };
            if (!$tipo) { $errors[] = "Linha {$line}: Tipo \"" . trim((string) $get($r, 'tipo')) . "\" inválido (use Etapa, Sub-etapa ou Atividade)."; continue; }

            $nome = trim((string) $get($r, 'nome'));
            if ($nome === '') $errors[] = "Linha {$line}: Nome obrigatório.";

            $code = trim((string) $get($r, 'codigo'));
            if ($code !== '') {
                if (isset($codes[$code])) $errors[] = "Linha {$line}: Código {$code} repetido (já usado na linha {$codes[$code]}).";
                $codes[$code] = $line;
            }

            $userId = null;
            $email = mb_strtolower(trim((string) $get($r, 'responsavel')));
            if ($email !== '') {
                $u = $userByEmail[$email] ?? null;
                if (!$u) $errors[] = "Linha {$line}: e-mail do Responsável \"{$email}\" não encontrado no Minutor (ou usuário inativo).";
                elseif ($u->type === 'cliente') $errors[] = "Linha {$line}: o Responsável \"{$email}\" é um usuário cliente — use um usuário da equipe.";
                else $userId = $u->id;
            }

            $ini = $this->parseDate($get($r, 'inicio'));
            $fim = $this->parseDate($get($r, 'fim'));
            if ($ini === false) $errors[] = "Linha {$line}: Início inválido (use dd/mm/aaaa).";
            if ($fim === false) $errors[] = "Linha {$line}: Fim inválido (use dd/mm/aaaa).";
            if ($ini && $fim && $fim->lt($ini)) $errors[] = "Linha {$line}: o Fim é antes do Início.";

            $horasRaw = trim(str_replace(',', '.', (string) $get($r, 'horas')));
            $horas = $horasRaw === '' ? 0.0 : (is_numeric($horasRaw) ? (float) $horasRaw : null);
            if ($horas === null || $horas < 0) { $errors[] = "Linha {$line}: Horas planejadas inválidas."; $horas = 0.0; }

            $item = ['line' => $line, 'tipo' => $tipo, 'code' => $code, 'nome' => $nome, 'user_id' => $userId,
                'ini' => $ini ?: null, 'fim' => $fim ?: null, 'horas' => $horas];

            if ($tipo === 'activity') {
                $prioRaw = $norm($get($r, 'prioridade'));
                $item['prio'] = match ($prioRaw) { '', 'média', 'media' => 'medium', 'baixa' => 'low', 'alta' => 'high', default => null };
                if (!$item['prio']) { $errors[] = "Linha {$line}: Prioridade \"" . trim((string) $get($r, 'prioridade')) . "\" inválida (Baixa, Média ou Alta)."; $item['prio'] = 'medium'; }
                $item['dep'] = trim((string) $get($r, 'depende'));
                $cli = $norm($get($r, 'cliente'));
                $item['cliente'] = in_array($cli, ['sim', 's', 'yes', 'x'], true);
                if (!in_array($cli, ['', 'sim', 's', 'yes', 'x', 'não', 'nao', 'n', 'no'], true)) $errors[] = "Linha {$line}: Cliente envolvido deve ser Sim ou Não.";
                $ce = trim((string) $get($r, 'cliente_email'));
                if ($ce !== '' && !filter_var($ce, FILTER_VALIDATE_EMAIL)) $errors[] = "Linha {$line}: E-mail do cliente \"{$ce}\" inválido.";
                if ($item['cliente'] && $ce === '') $errors[] = "Linha {$line}: Cliente envolvido = Sim exige o E-mail do cliente.";
                $item['cliente_email'] = $ce !== '' ? mb_strtolower($ce) : null;
                $item['descricao'] = trim((string) $get($r, 'descricao')) ?: null;
            }
            $items[] = $item;
        }

        // Hierarquia + dependências.
        $hasStage = false; $hasParent = false;
        $actCodes = [];
        foreach ($items as $it) {
            if ($it['tipo'] === 'stage') { $hasStage = true; $hasParent = true; }
            elseif ($it['tipo'] === 'sub') { if (!$hasStage) $errors[] = "Linha {$it['line']}: Sub-etapa sem uma Etapa acima."; $hasParent = true; }
            elseif (!$hasParent) $errors[] = "Linha {$it['line']}: Atividade sem uma Etapa ou Sub-etapa acima.";
            if ($it['tipo'] === 'activity' && $it['code'] !== '') $actCodes[$it['code']] = true;
        }
        foreach ($items as $it) {
            if (($it['tipo'] ?? '') === 'activity' && $it['dep'] !== '') {
                if ($it['dep'] === $it['code']) $errors[] = "Linha {$it['line']}: a atividade não pode depender dela mesma.";
                elseif (!isset($actCodes[$it['dep']])) $errors[] = "Linha {$it['line']}: Depende de \"{$it['dep']}\" não é o Código de uma atividade do layout.";
            }
        }
        if (!$items) $errors[] = 'Nenhuma linha preenchida no layout.';

        if ($errors) {
            return response()->json([
                'message' => count($errors) === 1 ? $errors[0] : 'O layout tem ' . count($errors) . ' problema(s). Nada foi importado.',
                'errors'  => array_slice($errors, 0, 200),
            ], 422);
        }

        // Datas → offsets a partir da menor data (âncora); novo início desloca tudo.
        $dates = [];
        foreach ($items as $it) { if ($it['ini']) $dates[] = $it['ini']; if ($it['fim']) $dates[] = $it['fim']; }
        $anchor = $dates ? min($dates)->copy()->startOfDay() : Carbon::today();
        $startDate = $request->filled('start_date') ? Carbon::parse($request->input('start_date'))->toDateString() : $anchor->toDateString();
        $off = fn (?Carbon $d) => $d ? (int) $anchor->diffInDays($d->copy()->startOfDay(), false) : null;

        // Monta o payload do motor de modelos (mesmo núcleo do "Aplicar modelo").
        $stagesPayload = [];
        $curStage = null; $curParent = null; $refN = 0;
        $codeToRef = [];
        $respByRef = [];
        foreach ($items as $it) {
            if ($it['tipo'] === 'stage' || $it['tipo'] === 'sub') {
                $ref = 's' . (++$refN);
                $stagesPayload[] = [
                    'ref' => $ref, 'parent_ref' => $it['tipo'] === 'sub' ? $curStage : null,
                    'name' => $it['nome'], 'hours_planned' => $it['horas'],
                    'start_offset' => $off($it['ini']), 'end_offset' => $off($it['fim']),
                    'responsible_user_id' => $it['user_id'], 'deliveries' => [],
                ];
                if ($it['tipo'] === 'stage') $curStage = $ref;
                $curParent = count($stagesPayload) - 1;
            } else {
                $ref = 'd' . (++$refN);
                if ($it['code'] !== '') $codeToRef[$it['code']] = $ref;
                $respByRef[$ref] = $it['user_id'];
                $stagesPayload[$curParent]['deliveries'][] = [
                    'ref' => $ref, 'title' => $it['nome'], 'hours_planned' => $it['horas'], 'priority' => $it['prio'],
                    'client_involved' => $it['cliente'], 'client_email' => $it['cliente_email'], 'extra_clients' => null,
                    'start_offset' => $off($it['ini']), 'end_offset' => $off($it['fim']),
                    'depends_on_code' => $it['dep'], 'dependency_type' => 'FS',
                    'responsible_user_id' => $it['user_id'], 'description' => $it['descricao'],
                ];
            }
        }
        foreach ($stagesPayload as &$s) {
            foreach ($s['deliveries'] as &$d) {
                $d['depends_on_ref'] = $d['depends_on_code'] !== '' ? ($codeToRef[$d['depends_on_code']] ?? null) : null;
                unset($d['depends_on_code']);
            }
            unset($d);
        }
        unset($s);

        $created = DB::transaction(function () use ($project, $stagesPayload, $startDate, $respByRef) {
            $map = $this->service->materialize($project, ['stages' => $stagesPayload], $startDate);
            // 1 consultor por atividade: o Responsável vira o alocado (primary) com as horas da atividade.
            foreach ($respByRef as $ref => $userId) {
                $d = $map['deliveries'][$ref] ?? null;
                if (!$d || !$userId) continue;
                StageAllocation::create([
                    'stage_id' => $d->stage_id, 'delivery_id' => $d->id, 'user_id' => $userId,
                    'planned_hours' => (float) ($d->hours_planned ?? 0),
                    'allocation_start_at' => $d->planned_start_at, 'allocation_end_at' => $d->due_date,
                    'is_primary' => true,
                ]);
                $u = User::find($userId);
                if ($u && in_array($u->type, ['consultor', 'parceiro_admin'], true)) {
                    $project->consultants()->syncWithoutDetaching([$userId]);
                }
            }
            return $map;
        });

        $nStages = collect($stagesPayload)->whereNull('parent_ref')->count();
        $nSubs = collect($stagesPayload)->whereNotNull('parent_ref')->count();
        $nActs = collect($stagesPayload)->sum(fn ($s) => count($s['deliveries']));
        return response()->json([
            'message' => "Cronograma importado: {$nStages} etapa(s), {$nSubs} sub-etapa(s) e {$nActs} atividade(s).",
            'stages' => $nStages, 'substages' => $nSubs, 'activities' => $nActs,
        ]);
    }

    /** dd/mm/aaaa, aaaa-mm-dd ou número de série do Excel. null = vazio; false = inválido. */
    private function parseDate($v): Carbon|false|null
    {
        if ($v === null || trim((string) $v) === '') return null;
        if (is_numeric($v) && (float) $v > 20000 && (float) $v < 80000) {
            return Carbon::instance(XlsDate::excelToDateTimeObject((float) $v))->startOfDay();
        }
        $s = trim((string) $v);
        if (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2}|\d{4})$#', $s, $m)) {
            $y = (int) $m[3]; if ($y < 100) $y += 2000;
            if (checkdate((int) $m[2], (int) $m[1], $y)) return Carbon::create($y, (int) $m[2], (int) $m[1])->startOfDay();
            return false;
        }
        if (preg_match('#^(\d{4})-(\d{2})-(\d{2})#', $s, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return Carbon::create((int) $m[1], (int) $m[2], (int) $m[3])->startOfDay();
        }
        return false;
    }
}
