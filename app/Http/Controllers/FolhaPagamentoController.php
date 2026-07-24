<?php

namespace App\Http\Controllers;

use App\Exports\FolhaBizifyExport;
use App\Exports\FolhaPagamentoExport;
use App\Models\FechamentoFolha;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Excel as ExcelType;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Folha de pagamento (planilha de importação): grid editável por consultor/mês + geração
 * do .xls idêntico ao template. Colunas auto (CPF/Matrícula/Status/Nome/Valor Hora) vêm do
 * usuário; Produção e Horas vêm do fechamento/apontamentos; o restante é editável e salvo.
 */
class FolhaPagamentoController extends Controller
{
    private function guard(Request $request): ?JsonResponse
    {
        $u = $request->user();
        if (!$u || !($u->isAdmin() || $u->isAdministrativo())) {
            return response()->json(['message' => 'Sem permissão para a folha de pagamento.'], 403);
        }
        return null;
    }

    private const RAHO_PARTNER_NAME = 'Raho';

    /**
     * id do parceiro Raho — fechamento dele vai INDIVIDUALMENTE pra cooperativa.
     * Hardcoded por nome (decisão de negócio: caso específico do Raho).
     */
    private static function rahoPartnerId(): ?int
    {
        return \App\Models\Partner::where('name', self::RAHO_PARTNER_NAME)->value('id');
    }

    /** Linhas do grid: cooperados regulares + as linhas-sócio fixas (totalmente editáveis). */
    private function buildRows(string $yearMonth, string $empresa = 'erpserv', bool $comDiretoria = true): array
    {
        $all = FechamentoFolha::where('year_month', $yearMonth)->where('empresa', $empresa)->get();

        // MESMA ENGRENAGEM P/ AS DUAS EMPRESAS: a folha puxa os cooperados do CADASTRO
        // (fechamento-based), filtrados pela EMPRESA BASE do funcionário (is_bizify).
        //  • ERPSERV → cooperados não-Bizify;  • BIZIFY → cooperados is_bizify.
        // Raho/parceiros/sócios manuais são conceitos da COOPERATIVA (só ERPSERV).
        $isBizify = $empresa === 'bizify';

        $fc   = app(FechamentoConsultorController::class);
        $data = $fc->buildConsultoresData($yearMonth);
        $byUser = collect(array_merge($data['horistas'], $data['banco_horas'], $data['fixos']))->keyBy('user_id');

        $folhaByUser  = $all->whereNotNull('user_id')->keyBy('user_id');
        $folhaBySocio = $all->whereNotNull('socio_key')->keyBy('socio_key');

        // Reembolso AUTO (read-only): soma das despesas marcadas como "pagar no
        // fechamento" (pagar_no_fechamento=true, status=approved) com expense_date
        // no mês da folha. Indexado por user_id. Sócios manuais não têm user_id
        // -> não recebem reemb_auto (continuam só com reemb manual).
        // Soma com $reemb (manual) -> $total_rend.
        $rangeFrom = "{$yearMonth}-01";
        $rangeTo   = \Carbon\Carbon::parse($rangeFrom)->endOfMonth()->toDateString();
        $reembAutoByUser = \App\Models\Expense::query()
            ->where('status', \App\Models\Expense::STATUS_APPROVED)
            ->where('pagar_no_fechamento', true)
            ->whereBetween('expense_date', [$rangeFrom, $rangeTo])
            ->selectRaw('user_id, SUM(amount) AS total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        // Ajustes do fechamento do consultor (desconto/adiantamento/adicional) por user_id —
        // usados pra calcular o "valor total do fechamento" das linhas do Raho cooperado.
        $ajustesMap = \App\Models\FechamentoConsultorAjuste::where('year_month', $yearMonth)->get()->keyBy('user_id');

        // Parceiro "Raho": fechamento vai INDIVIDUALMENTE pra cooperativa — cada usuário
        // do Raho vira uma linha própria (azul, identificada, 100% editável), fora da
        // lista normal de cooperados (sem duplicar). Hardcoded por nome.
        $rahoId = self::rahoPartnerId();

        $rows = [];

        // ── Cooperados: TODO usuário (qualquer perfil exceto cliente) marcado cooperado.
        // Exclui os sócios (entram como linha própria). Produção/horas vêm do fechamento
        // do consultor quando houver apontamentos; senão 0 / 160.
        $cooperados = User::where('contract_type', 'cooperado')->where('enabled', true)
            ->whereNotIn('type', ['cliente'])
            ->where(fn ($q) => $isBizify
                ? $q->where('is_bizify', true)
                : $q->where('is_bizify', false)->orWhereNull('is_bizify'))
            ->orderBy('name')->get();
        foreach ($cooperados as $u) {
            if ($u->partner_id) {
                // Usuários de PARCEIRO não entram como cooperado individual:
                //  • Raho → linha própria (seção azul abaixo);
                //  • demais parceiros → consolidados numa única linha no admin (seção parceiros).
                continue;
            }
            $uid = $u->id;
            $c   = $byUser[$uid] ?? []; // dados do fechamento (se for consultor com apontamento)
            $aj  = $ajustesMap[$uid] ?? null; // ajustes do fechamento do consultor (desconto/adiantamento/adicional)

            $f = $folhaByUser[$uid] ?? null;

            $apontHoras   = round((float) ($c['horas_trabalhadas'] ?? 0), 2);
            $reembAuto    = round((float) ($reembAutoByUser[$uid] ?? 0), 2);
            // Produção = VALOR TOTAL DO FECHAMENTO (recebimento), AGORA INCLUINDO a despesa:
            //   recebimento = serviço + despesa − desconto − adiantamento + adicional.
            // Como a despesa já está dentro da produção, NÃO somamos reembAuto de novo
            // (senão contaria a despesa em dobro). Linhas sem fechamento mantêm reembAuto.
            $hasFech      = array_key_exists('recebimento', $c) || array_key_exists('total', $c);
            $fechDesp     = $hasFech ? round((float) ($c['total_despesas'] ?? 0), 2) : 0.0;
            $producaoCalc = $hasFech
                ? round((float) ($c['recebimento'] ?? $c['total'] ?? 0), 2)  // recebimento (com despesa)
                : 0.0;
            $producao     = ($f && $f->producao !== null) ? round((float) $f->producao, 2) : $producaoCalc;
            $horas        = 160.0; // horas FIXAS (não via apontamento); Produção segue do fechamento

            // Valor hora só p/ vínculo por hora; fixo/mensal NÃO traz valor.
            $valorHora = ($u && $u->rate_type === 'hourly') ? round((float) $u->hourly_rate, 4) : 0.0;

            $variavel     = $f ? (float) $f->variavel : 0.0;
            $reemb        = $f ? (float) $f->reemb : 0.0;
            // Cooperado com fechamento: despesa já está na produção → não soma reembAuto.
            $reembAutoCalc = $hasFech ? 0.0 : $reembAuto;
            $descontos    = $f ? (float) $f->descontos_diversos : 0.0;
            $adiantamento = $f ? (float) $f->adiantamento : 0.0;
            $hm           = $f && $f->horista_mensalista
                ? $f->horista_mensalista
                : (($c['consultant_type'] ?? $u->consultant_type) === 'horista' ? 'Horista' : 'Mensalista');

            $totalRend    = round($producao + $variavel + $reemb + $reembAutoCalc, 2);
            $totalDebitos = round($descontos + $adiantamento, 2);

            $rows[] = [
                'row_key'            => 'u:' . $uid,
                'is_socio'           => false,
                'is_cooperado'       => true, // linha de cooperado real (User contract_type=cooperado, sem parceiro)
                'is_bizify'          => (bool) ($u->is_bizify ?? false), // distinção Bizify (cadastro do usuário)
                'inativo'            => false,
                'cancelado'          => $f ? (bool) $f->cancelado : false,
                'user_id'            => $uid,
                'socio_key'          => null,
                'cpf'                => $u?->cpf ?? '',
                'matricula'          => $u?->matricula ?? '',
                'status'             => $u?->payroll_status ?? '',
                'nome'               => $u?->full_name ?: ($u?->name ?? ($c['nome'] ?? '')),
                'dias'               => $f ? (float) $f->dias_trabalhados : 0.0,
                'horas'              => $horas,
                'horas_apontamentos' => $apontHoras,
                'valor_hora'         => $valorHora,
                'producao'           => $producao,
                'producao_calc'      => $producaoCalc, // valor calculado do fechamento (legenda no FE)
                // Componentes do fechamento do consultor (legenda: serv − desc − adiant + adic + desp = produção).
                'fech_serv'          => round((float) ($c['total'] ?? 0), 2),
                'fech_desconto'      => $aj ? (float) $aj->desconto : 0.0,
                'fech_adiantamento'  => $aj ? (float) $aj->adiantamento : 0.0,
                'fech_emprestimo'    => round((float) ($c['emprestimo_aporte'] ?? 0), 2),
                'fech_adicional'     => $aj ? (float) $aj->adicional : 0.0,
                'fech_desp'          => $fechDesp, // despesa já incorporada à produção
                'variavel'           => $variavel,
                'reemb'              => $reemb,
                'reemb_auto'         => $reembAutoCalc, // 0 p/ cooperado c/ fechamento (despesa já na produção)
                'descontos'          => $descontos,
                'adiantamento'       => $adiantamento,
                'horista_mensalista' => $hm,
                'total_rend'         => $totalRend,
                'total_debitos'      => $totalDebitos,
                'liquido'            => round($totalRend - $totalDebitos, 2),
            ];
        }

        // ── Raho: cada usuário do parceiro vira linha própria (azul, identificada,
        //    100% editável). Keyed por user_id; valores editáveis vêm da folha salva,
        //    com defaults do cadastro/fechamento. Novo usuário do Raho → nova linha auto. ──
        if ($rahoId && !$isBizify) {
            // Valores CALCULADOS do mês filtrado: vêm do fechamento do PARCEIRO (horas × taxa).
            $rahoPartner = \App\Models\Partner::find($rahoId);
            $rahoCalc = $rahoPartner
                ? collect(app(FechamentoParceiroController::class)->consultoresData($rahoPartner, $yearMonth))->keyBy('user_id')
                : collect();
            // Inclui desativados também (aparecem em "afastamento", ocultação manual).
            $rahoUsers = User::where('partner_id', $rahoId)->orderBy('name')->get();
            foreach ($rahoUsers as $u) {
                $uid  = $u->id;
                $f    = $folhaByUser[$uid] ?? null;
                $calc = $rahoCalc[$uid] ?? [];
                $aj   = $ajustesMap[$uid] ?? null; // ajustes do fechamento do consultor

                // Original (calculado do mês): horas/taxa/produção do fechamento do parceiro.
                $horasCalc     = round((float) ($calc['horas'] ?? 0), 2);
                $valorHoraCalc = round((float) ($calc['valor_hora'] ?? 0), 2);
                $producaoCalc  = round((float) ($calc['total'] ?? 0), 2);
                $fechServ      = $producaoCalc; // base de serviço (antes dos ajustes do fechamento)
                $reembAuto     = round((float) ($reembAutoByUser[$uid] ?? 0), 2);
                $isCoop        = $u->contract_type === 'cooperado';
                // Cooperado: Produção = VALOR TOTAL DO FECHAMENTO, INCLUINDO a despesa:
                //   total − desconto − adiantamento + adicional + despesa.
                // A despesa entra na produção, então NÃO soma reembAuto de novo (evita duplicar).
                $fechDesp      = $isCoop ? $reembAuto : 0.0;
                if ($isCoop) {
                    if ($aj) {
                        $producaoCalc = round($producaoCalc - (float) $aj->desconto - (float) $aj->adiantamento + (float) $aj->adicional, 2);
                    }
                    $producaoCalc = round($producaoCalc + $reembAuto, 2); // + despesa
                }

                // Atual = salvo (editado) quando houver; senão o calculado (prefill).
                $horas        = ($f && $f->horas_trabalhadas !== null) ? (float) $f->horas_trabalhadas : $horasCalc;
                $valorHora    = ($f && $f->valor_hora !== null) ? (float) $f->valor_hora : $valorHoraCalc;
                $producao     = ($f && $f->producao !== null) ? (float) $f->producao : $producaoCalc;
                $variavel     = $f ? (float) $f->variavel : 0.0;
                $reemb        = $f ? (float) $f->reemb : 0.0;
                $reembAutoCalc = $isCoop ? 0.0 : $reembAuto; // cooperado: despesa já está na produção
                $descontos    = $f ? (float) $f->descontos_diversos : 0.0;
                $adiantamento = $f ? (float) $f->adiantamento : 0.0;

                $totalRend    = round($producao + $variavel + $reemb + $reembAutoCalc, 2);
                $totalDebitos = round($descontos + $adiantamento, 2);

                $rows[] = [
                    'row_key'            => 'u:' . $uid,
                    'is_socio'           => false, // identidade (cpf/nome/status) vem do usuário (read-only); VALORES editáveis via is_raho
                    'is_raho'            => true,
                    'is_cooperado'       => $isCoop, // Raho é linha-por-usuário; cooperado entra no filtro
                    'is_bizify'          => (bool) ($u->is_bizify ?? false),
                    'partner_label'      => 'Raho',
                    'inativo'            => !$u->enabled, // desativado => "em afastamento"
                    'cancelado'          => $f ? (bool) $f->cancelado : false,
                    'user_id'            => $uid,
                    'socio_key'          => null,
                    'cpf'                => $u->cpf ?? '',
                    'matricula'          => $u->matricula ?? '',
                    'status'             => $u->payroll_status ?? '',
                    'nome'               => $u->full_name ?: $u->name,
                    'dias'               => $f ? (float) $f->dias_trabalhados : 0.0,
                    'horas'              => $horas,
                    'horas_apontamentos' => $horasCalc,
                    'valor_hora'         => $valorHora,
                    'producao'           => $producao,
                    // Valores ORIGINAIS calculados (p/ legenda "original" quando alterado).
                    'horas_calc'         => $horasCalc,
                    'valor_hora_calc'    => $valorHoraCalc,
                    'producao_calc'      => $producaoCalc,
                    // Componentes do fechamento do consultor (legenda: serv − desc − adiant + adic + desp = produção).
                    'fech_serv'          => round((float) $fechServ, 2),
                    'fech_desconto'      => ($isCoop && $aj) ? (float) $aj->desconto : 0.0,
                    'fech_adiantamento'  => ($isCoop && $aj) ? (float) $aj->adiantamento : 0.0,
                    'fech_adicional'     => ($isCoop && $aj) ? (float) $aj->adicional : 0.0,
                    'fech_desp'          => $fechDesp, // despesa já incorporada à produção (cooperado)
                    'variavel'           => $variavel,
                    'reemb'              => $reemb,
                    'reemb_auto'         => $reembAutoCalc, // 0 p/ cooperado (despesa já na produção)
                    'descontos'          => $descontos,
                    'adiantamento'       => $adiantamento,
                    'horista_mensalista' => $f && $f->horista_mensalista ? $f->horista_mensalista
                                          : ($u->consultant_type === 'horista' ? 'Horista' : 'Mensalista'),
                    'total_rend'         => $totalRend,
                    'total_debitos'      => $totalDebitos,
                    'liquido'            => round($totalRend - $totalDebitos, 2),
                ];
            }
        }

        // ── Parceiros (exceto Raho): consolida o parceiro numa ÚNICA linha, no parceiro
        //    admin (is_executive), com a APURAÇÃO TOTAL do parceiro (soma do fechamento de
        //    TODOS os membros: horas somadas + valor total). Membros comuns NÃO aparecem.
        //    Parceiro SEM admin is_executive é ignorado (não entra na folha). ──
        $partners = $isBizify ? collect() : \App\Models\Partner::query()
            ->when($rahoId, fn ($q) => $q->where('id', '!=', $rahoId))
            ->orderBy('name')->get();
        foreach ($partners as $partner) {
            // Representante do parceiro na folha ("quem sobe pra folha"): escolha EXPLÍCITA
            // no cadastro do parceiro (folha_user_id) → fallback is_executive (legado).
            $admin = null;
            if ($partner->folha_user_id) {
                $admin = User::where('partner_id', $partner->id)
                    ->where('id', $partner->folha_user_id)->first();
            }
            if (!$admin) {
                $admin = User::where('partner_id', $partner->id)
                    ->where('is_executive', true)
                    ->orderBy('id')->first();
            }
            // Folha COOPERATIVA: só entra parceiro COOPERADO (fonte de verdade = o próprio
            // parceiro; aceita também o legado onde só o admin ficou marcado cooperado).
            // Parceiro PJ é pago no Fechamento Parceiros, não nesta folha.
            $isCoop = $partner->contract_type === 'cooperado'
                || ($admin && $admin->contract_type === 'cooperado');
            if (!$admin || !$isCoop) {
                continue; // sem representante OU parceiro não-cooperado → fora da folha
            }

            $parceiroCtrl  = app(FechamentoParceiroController::class);
            $calc = collect($parceiroCtrl->consultoresData($partner, $yearMonth));
            $totalHoras    = round((float) $calc->sum(fn ($r) => (float) ($r['horas'] ?? 0)), 2);
            $totalServicos = round((float) $calc->sum(fn ($r) => (float) ($r['total'] ?? 0)), 2);
            // Despesas a reembolsar no fechamento (não-pagas) entram na PRODUÇÃO — igual cooperado.
            $totalDespesas = round((float) collect($parceiroCtrl->despesasData((int) $partner->id, $yearMonth))
                ->where('is_paid', false)->sum('valor'), 2);
            $totalApuracao = round($totalServicos + $totalDespesas, 2); // recebimento = serviços + despesas

            $uid = $admin->id;
            $f   = $folhaByUser[$uid] ?? null;

            // Atual = salvo (editado) quando houver; senão o calculado (prefill).
            $horas        = ($f && $f->horas_trabalhadas !== null) ? (float) $f->horas_trabalhadas : $totalHoras;
            $producao     = ($f && $f->producao !== null) ? (float) $f->producao : $totalApuracao;
            $variavel     = $f ? (float) $f->variavel : 0.0;
            $reemb        = $f ? (float) $f->reemb : 0.0;
            $descontos    = $f ? (float) $f->descontos_diversos : 0.0;
            $adiantamento = $f ? (float) $f->adiantamento : 0.0;

            $totalRend    = round($producao + $variavel + $reemb, 2);
            $totalDebitos = round($descontos + $adiantamento, 2);

            $rows[] = [
                'row_key'            => 'u:' . $uid,
                'is_socio'           => false,
                'is_raho'            => false,
                'is_parceiro_total'  => true,
                // Parceiro cooperado é apurado 100% no representante → esta linha
                // consolidada representa o cooperado e entra no filtro.
                'is_cooperado'       => $isCoop,
                'is_bizify'          => (bool) ($admin->is_bizify ?? false),
                'partner_label'      => $partner->name,
                'inativo'            => !$admin->enabled,
                'cancelado'          => $f ? (bool) $f->cancelado : false,
                'user_id'            => $uid,
                'socio_key'          => null,
                'cpf'                => $admin->cpf ?? '',
                'matricula'          => $admin->matricula ?? '',
                'status'             => $admin->payroll_status ?? '',
                'nome'               => $admin->full_name ?: $admin->name,
                'dias'               => $f ? (float) $f->dias_trabalhados : 0.0,
                'horas'              => $horas,
                'horas_apontamentos' => $totalHoras,
                'valor_hora'         => 0.0, // consolidado: sem valor/hora (FE mostra "—")
                'producao'           => $producao,
                'horas_calc'         => $totalHoras,
                'valor_hora_calc'    => 0.0,
                'producao_calc'      => $totalApuracao,
                'fech_serv'          => $totalServicos,
                'fech_desconto'      => 0.0,
                'fech_adiantamento'  => 0.0,
                'fech_adicional'     => 0.0,
                'fech_desp'          => $totalDespesas,
                'variavel'           => $variavel,
                'reemb'              => $reemb,
                'reemb_auto'         => 0.0,
                'descontos'          => $descontos,
                'adiantamento'       => $adiantamento,
                'horista_mensalista' => $f && $f->horista_mensalista ? $f->horista_mensalista : 'Mensalista',
                'total_rend'         => $totalRend,
                'total_debitos'      => $totalDebitos,
                'liquido'            => round($totalRend - $totalDebitos, 2),
            ];
        }

        // Identidades (CPF só-dígitos + matrícula) das linhas já criadas por usuário real
        // (cooperado/Raho/parceiro). Diretores migraram p/ User + Fechamento Diretoria, mas a
        // linha-sócio manual LEGADA ainda existe na fechamento_folha → duplicava o diretor.
        // Suprimimos a linha-sócio que colide por CPF ou matrícula com um usuário já incluído.
        $cpfDigits = fn ($v) => preg_replace('/\D+/', '', (string) $v);
        $userCpfs       = collect($rows)->whereNotNull('user_id')->pluck('cpf')->map($cpfDigits)->filter()->flip();
        $userMatriculas = collect($rows)->whereNotNull('user_id')->pluck('matricula')
            ->map(fn ($m) => trim((string) $m))->filter()->flip();

        // ── Linhas manuais ("Nova linha editável") — inclui os sócios (migrados p/ manual). ──
        //    Só COOPERATIVA (ERPSERV); a folha Bizify é 100% cadastro (sem sócios manuais).
        foreach (($isBizify ? [] : $folhaBySocio) as $key => $f) {
            // Diretor já representado por usuário (cooperado + Fechamento Diretoria): não duplica.
            $sCpf = $cpfDigits($f->cpf);
            $sMat = trim((string) $f->matricula);
            if (($sCpf !== '' && $userCpfs->has($sCpf)) || ($sMat !== '' && $userMatriculas->has($sMat))) {
                continue;
            }
            $variavel     = (float) $f->variavel;
            $reemb        = (float) $f->reemb;
            $descontos    = (float) $f->descontos_diversos;
            $adiantamento = (float) $f->adiantamento;
            $producao     = $f->producao !== null ? (float) $f->producao : 0.0;
            $totalRend    = round($producao + $variavel + $reemb, 2);
            $totalDebitos = round($descontos + $adiantamento, 2);

            $rows[] = [
                'row_key'            => 's:' . $key,
                'is_socio'           => true,
                'inativo'            => false,
                'cancelado'          => (bool) $f->cancelado,
                'user_id'            => null,
                'socio_key'          => $key,
                'cpf'                => $f->cpf ?? '',
                'matricula'          => $f->matricula ?? '',
                'status'             => $f->status ?? '',
                'nome'               => $f->nome ?? '',
                'dias'               => (float) $f->dias_trabalhados,
                'horas'              => $f->horas_trabalhadas !== null ? (float) $f->horas_trabalhadas : 160.0,
                'horas_apontamentos' => 0.0,
                'valor_hora'         => $f->valor_hora !== null ? (float) $f->valor_hora : 0.0,
                'producao'           => $producao,
                'variavel'           => $variavel,
                'reemb'              => $reemb,
                'reemb_auto'         => 0.0, // sócios manuais não têm user_id pra rastrear despesas
                'descontos'          => $descontos,
                'adiantamento'       => $adiantamento,
                'horista_mensalista' => $f->horista_mensalista ?: 'Horista',
                'total_rend'         => $totalRend,
                'total_debitos'      => $totalDebitos,
                'liquido'            => round($totalRend - $totalDebitos, 2),
            ];
        }

        // ── Cooperados DESATIVADOS (linha "em afastamento") ──
        // Usuário desativado continua aparecendo (afastamento, borda vermelha) até que
        // a ocultação seja feita manualmente (cancelar) quando não tiver participação.
        $jaIncluidos = collect($rows)->whereNotNull('user_id')->pluck('user_id')->all();
        $inativos = User::where('contract_type', 'cooperado')
            ->where('enabled', false)
            ->where(fn ($q) => $isBizify
                ? $q->where('is_bizify', true)
                : $q->where('is_bizify', false)->orWhereNull('is_bizify'))
            ->whereNotIn('id', $jaIncluidos)
            ->get();
        foreach ($inativos as $u) {
            $f = $folhaByUser[$u->id] ?? null;
            $valorHora    = ($u->rate_type === 'hourly') ? round((float) $u->hourly_rate, 4) : 0.0;
            $producao     = $f && $f->producao !== null ? (float) $f->producao : 0.0;
            $variavel     = $f ? (float) $f->variavel : 0.0;
            $reemb        = $f ? (float) $f->reemb : 0.0;
            $reembAuto    = round((float) ($reembAutoByUser[$u->id] ?? 0), 2);
            $descontos    = $f ? (float) $f->descontos_diversos : 0.0;
            $adiantamento = $f ? (float) $f->adiantamento : 0.0;
            $totalRend    = round($producao + $variavel + $reemb + $reembAuto, 2);
            $totalDebitos = round($descontos + $adiantamento, 2);

            $rows[] = [
                'row_key'            => 'u:' . $u->id,
                'is_socio'           => false,
                'inativo'            => true,
                'cancelado'          => $f ? (bool) $f->cancelado : false,
                'user_id'            => $u->id,
                'socio_key'          => null,
                'cpf'                => $u->cpf ?? '',
                'matricula'          => $u->matricula ?? '',
                'status'             => $u->payroll_status ?? '',
                'nome'               => $u->full_name ?: $u->name,
                'dias'               => $f ? (float) $f->dias_trabalhados : 0.0,
                'horas'              => $f && $f->horas_trabalhadas !== null ? (float) $f->horas_trabalhadas : 160.0,
                'horas_apontamentos' => 0.0,
                'valor_hora'         => $valorHora,
                'producao'           => $producao,
                'variavel'           => $variavel,
                'reemb'              => $reemb,
                'reemb_auto'         => $reembAuto, // despesas pagas no fechamento do mês (read-only no FE)
                'descontos'          => $descontos,
                'adiantamento'       => $adiantamento,
                'horista_mensalista' => $f && $f->horista_mensalista ? $f->horista_mensalista : 'Mensalista',
                'total_rend'         => $totalRend,
                'total_debitos'      => $totalDebitos,
                'liquido'            => round($totalRend - $totalDebitos, 2),
            ];
        }

        $rows = $this->injectDiretoria($rows, 'erpserv', $yearMonth, $comDiretoria);
        usort($rows, fn ($a, $b) => strcasecmp((string) $a['nome'], (string) $b['nome']));
        return $rows;
    }

    /**
     * Soma na produção (por empresa) o valor a receber + taxa da divisão por cooperativa
     * do Fechamento Diretoria. Cria a linha do diretor se ele não estiver na folha.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function injectDiretoria(array $rows, string $empresa, string $yearMonth, bool $ativo = true): array
    {
        if (!$ativo) {
            return $rows;
        }
        $headers = \App\Models\FechamentoDiretoria::where('year_month', $yearMonth)->get();
        if ($headers->isEmpty()) {
            return $rows;
        }

        // Contribuição por diretor = valor a receber (coop) + taxa (coop) da empresa.
        // Quando o repasse é DIVIDIDO entre as duas coops, a coop SEM INSS grava o valor
        // na coluna VARIÁVEL; a coop COM INSS (e o caso de coop única) grava em PRODUÇÃO.
        $contrib = [];
        foreach ($headers as $h) {
            $valErp = (float) $h->valor_coop_erpserv; $taxErp = (float) $h->taxa_coop_erpserv;
            $valBiz = (float) $h->valor_coop_bizify;  $taxBiz = (float) $h->taxa_coop_bizify;
            $valor = $empresa === 'bizify' ? $valBiz : $valErp;
            $taxa  = $empresa === 'bizify' ? $taxBiz : $taxErp;
            $c = round($valor + $taxa, 2);
            if ($c == 0.0) {
                continue;
            }
            // Dividido = ambas as coops com repasse > 0.
            $dividido = round($valErp + $taxErp, 2) != 0.0 && round($valBiz + $taxBiz, 2) != 0.0;
            // Coop que recolheu o INSS = a com resíduo de INSS na taxa (taxa − repasse×1%).
            $inssErp = round($taxErp - ($valErp + $taxErp) * 0.01, 2);
            $inssBiz = round($taxBiz - ($valBiz + $taxBiz) * 0.01, 2);
            $inssCoop = $inssBiz > $inssErp + 0.01 ? 'bizify' : 'erpserv';
            $col = ($dividido && $empresa !== $inssCoop) ? 'variavel' : 'producao';
            $prev = $contrib[$h->user_id] ?? ['amount' => 0.0, 'col' => $col];
            $contrib[$h->user_id] = ['amount' => round($prev['amount'] + $c, 2), 'col' => $col];
        }
        if (!$contrib) {
            return $rows;
        }

        // Usuários-diretores e mapa matrícula→uid. Linhas SEM user_id (ex.: Bizify, que é
        // 100% manual e identificada por socio_key/matrícula) só casam por matrícula —
        // senão o passo 2 cria uma linha de diretor a mais e DUPLICA o diretor.
        $dirUsers = \App\Models\User::whereIn('id', array_keys($contrib))->get()->keyBy('id');
        $uidByMatricula = [];
        foreach ($dirUsers as $du) {
            $m = trim((string) $du->matricula);
            if ($m !== '') {
                $uidByMatricula[$m] = $du->id;
            }
        }

        // 1) Linhas COM user_id (ERPSERV cooperado): soma a contribuição na própria linha
        //    (a identidade dessa linha já vem do cadastro). Linhas SEM user_id que casam por
        //    matrícula são linhas manuais LEGADAS do diretor (Bizify) — descartadas aqui pra
        //    que o passo 2 recrie a linha 100% a partir do cadastro + Fechamento Diretoria,
        //    igual à ERPSERV (identidade travada, valores do Fechamento Diretoria).
        $usados = [];
        $descartar = [];
        foreach ($rows as &$row) {
            $uid = $row['user_id'] ?? null;
            if ($uid !== null && isset($contrib[$uid]) && !isset($usados[$uid])) {
                $c   = $contrib[$uid]['amount'];
                $col = $contrib[$uid]['col'];
                $row[$col] = round((float) ($row[$col] ?? 0) + $c, 2);
                if (array_key_exists('total_rend', $row))     $row['total_rend']     = round((float) $row['total_rend'] + $c, 2);
                if (array_key_exists('total_creditos', $row)) $row['total_creditos'] = round((float) $row['total_creditos'] + $c, 2);
                $row['liquido'] = round((float) ($row['liquido'] ?? 0) + $c, 2);
                $row['from_diretoria'] = true;
                $usados[$uid] = true;
            } elseif ($uid === null) {
                $m = trim((string) ($row['matricula'] ?? ''));
                if ($m !== '' && isset($uidByMatricula[$m])) {
                    $descartar[$row['row_key']] = true; // linha manual legada do diretor → some
                }
            }
        }
        unset($row);
        if ($descartar) {
            $rows = array_values(array_filter($rows, fn ($r) => !isset($descartar[$r['row_key']])));
        }

        // 2) Diretores sem linha na empresa → cria a linha (na coluna certa).
        $faltam = array_diff_key($contrib, $usados);
        if ($faltam) {
            $users = $dirUsers;
            foreach ($faltam as $uid => $info) {
                $u = $users->get($uid);
                if (!$u) {
                    continue;
                }
                $rows[] = $empresa === 'bizify'
                    ? $this->diretoriaBizifyRow($u, $info['amount'], $info['col'])
                    : $this->diretoriaErpservRow($u, $info['amount'], $info['col']);
            }
        }

        return $rows;
    }

    /** Linha de diretor (sem folha própria) na aba ERPSERV — valor vem da divisão por coop. */
    private function diretoriaErpservRow($u, float $c, string $col = 'producao'): array
    {
        return [
            'row_key' => 'd:' . $u->id, 'is_socio' => false, 'is_cooperado' => true, 'is_bizify' => false,
            'inativo' => false, 'cancelado' => false, 'from_diretoria' => true,
            'user_id' => $u->id, 'socio_key' => null,
            'cpf' => $u->cpf ?? '', 'matricula' => $u->matricula ?? '', 'status' => $u->payroll_status ?? '',
            'nome' => $u->full_name ?: $u->name,
            'dias' => 0.0, 'horas' => 0.0, 'horas_apontamentos' => 0.0, 'valor_hora' => 0.0,
            'producao' => $col === 'producao' ? $c : 0.0, 'producao_calc' => 0.0,
            'fech_serv' => 0.0, 'fech_desconto' => 0.0, 'fech_adiantamento' => 0.0, 'fech_adicional' => 0.0, 'fech_desp' => 0.0,
            'variavel' => $col === 'variavel' ? $c : 0.0, 'reemb' => 0.0, 'reemb_auto' => 0.0, 'descontos' => 0.0, 'adiantamento' => 0.0,
            'horista_mensalista' => 'Mensalista',
            'total_rend' => $c, 'total_debitos' => 0.0, 'liquido' => $c,
        ];
    }

    /** Linha de diretor (sem folha própria) na aba BIZIFY — valor vem da divisão por coop. */
    private function diretoriaBizifyRow($u, float $c, string $col = 'producao'): array
    {
        return [
            'row_key' => 'd:' . $u->id, 'is_bizify' => true, 'is_socio' => false, 'is_raho' => false,
            'inativo' => false, 'carried' => false, 'cancelado' => false, 'from_diretoria' => true,
            'user_id' => $u->id, 'socio_key' => null,
            'matricula' => $u->matricula ?? '', 'nome' => $u->full_name ?: $u->name, 'status' => $u->payroll_status ?? '',
            'producao' => $col === 'producao' ? $c : 0.0, 'variavel' => $col === 'variavel' ? $c : 0.0, 'aj_custo' => 0.0, 'reemb' => 0.0, 'adto' => 0.0,
            'descontos' => 0.0, 'adiantamento' => 0.0,
            'total_creditos' => $c, 'total_debitos' => 0.0, 'liquido' => $c,
        ];
    }

    /**
     * Linhas da aba BIZIFY: lançamentos manuais (avulsos, identidade da própria linha).
     * Colunas próprias: Produção, Variável, Aj Custo, Reemb, Adto (créditos) / Descontos,
     * Adiantamento (débitos). Totais calculados. socio_key = matrícula.
     */
    private function buildBizifyRows($all, bool $carried = false): array
    {
        $rows = [];
        foreach ($all->whereNotNull('socio_key') as $f) {
            $producao     = (float) ($f->producao ?? 0);
            $variavel     = (float) $f->variavel;
            $ajCusto      = (float) ($f->aj_custo ?? 0);
            $reemb        = (float) $f->reemb;
            $adto         = (float) ($f->adto ?? 0);
            $descontos    = (float) $f->descontos_diversos;
            $adiantamento = (float) $f->adiantamento;

            $totalCred = round($producao + $variavel + $ajCusto + $reemb + $adto, 2);
            $totalDeb  = round($descontos + $adiantamento, 2);

            $rows[] = [
                'row_key'        => 'b:' . $f->socio_key,
                'is_bizify'      => true,
                'is_socio'       => false,
                'is_raho'        => false,
                'inativo'        => false,
                'carried'        => $carried, // veio do mês anterior (prefill, ainda não salvo neste mês)
                'cancelado'      => $carried ? false : (bool) $f->cancelado,
                'user_id'        => null,
                'socio_key'      => $f->socio_key,
                'matricula'      => $f->matricula ?? '',
                'nome'           => $f->nome ?? '',
                'status'         => $f->status ?? '', // Operação
                'producao'       => $producao,
                'variavel'       => $variavel,
                'aj_custo'       => $ajCusto,
                'reemb'          => $reemb,
                'adto'           => $adto,
                'descontos'      => $descontos,
                'adiantamento'   => $adiantamento,
                'total_creditos' => $totalCred,
                'total_debitos'  => $totalDeb,
                'liquido'        => round($totalCred - $totalDeb, 2),
            ];
        }
        usort($rows, fn ($a, $b) => strcasecmp((string) $a['nome'], (string) $b['nome']));
        return $rows;
    }

    public function grid(Request $request, string $yearMonth): JsonResponse
    {
        if ($r = $this->guard($request)) {
            return $r;
        }
        $empresa = $request->query('empresa') === 'bizify' ? 'bizify' : 'erpserv';

        // Multi-empresa (flag ON): a Folha Cooperativa é ÚNICA por EMPRESA BASE (a ativa) —
        // ignora o param das abas e usa a empresa ativa. O FE esconde as abas quando vier `company`.
        $companyName = null;
        if (config('multiempresa.scoping_enabled')) {
            $active = \App\Models\Company::find(app(\App\Services\CompanyContext::class)->id());
            if ($active) {
                $empresa     = $active->slug === 'bizify' ? 'bizify' : 'erpserv';
                $companyName = $active->name;
            }
        }
        return response()->json(['data' => $this->buildRows($yearMonth, $empresa), 'company' => $companyName, 'empresa' => $companyName ? $empresa : null]);
    }

    /**
     * Linha da folha de um usuário no mês (procura nas duas empresas).
     * Reutilizada pelo Fechamento Diretoria para montar a folha do diretor.
     *
     * @return array<string, mixed>|null
     */
    public function rowForUser(string $yearMonth, int $userId): ?array
    {
        foreach (['erpserv', 'bizify'] as $empresa) {
            // comDiretoria=false: a folha-base (sem a injeção da própria diretoria),
            // pra não realimentar o repasse de forma circular.
            foreach ($this->buildRows($yearMonth, $empresa, false) as $row) {
                if ((int) ($row['user_id'] ?? 0) === $userId) {
                    return $row;
                }
            }
        }
        return null;
    }

    public function save(Request $request, string $yearMonth): JsonResponse
    {
        if ($r = $this->guard($request)) {
            return $r;
        }
        $request->validate([
            'empresa'                        => 'nullable|string|in:erpserv,bizify',
            'entries'                        => 'required|array',
            'entries.*.user_id'              => 'nullable|integer|exists:users,id',
            'entries.*.socio_key'            => 'nullable|string|max:60',
            'entries.*.cpf'                  => 'nullable|string|max:20',
            'entries.*.matricula'            => 'nullable|string|max:30',
            'entries.*.nome'                 => 'nullable|string|max:255',
            'entries.*.status'               => 'nullable|string|max:40',
            'entries.*.valor_hora'           => 'nullable|numeric',
            'entries.*.producao'             => 'nullable|numeric',
            'entries.*.dias_trabalhados'     => 'nullable|numeric',
            'entries.*.horas_trabalhadas'    => 'nullable|numeric',
            'entries.*.variavel'             => 'nullable|numeric',
            'entries.*.reemb'                => 'nullable|numeric',
            'entries.*.descontos_diversos'   => 'nullable|numeric',
            'entries.*.adiantamento'         => 'nullable|numeric',
            'entries.*.aj_custo'             => 'nullable|numeric',
            'entries.*.adto'                 => 'nullable|numeric',
            'entries.*.horista_mensalista'   => 'nullable|string|max:20',
        ]);

        $empresa = $request->input('empresa') === 'bizify' ? 'bizify' : 'erpserv';
        // Multi-empresa: company_id da linha SEMPRE deriva da `empresa` (não da empresa ativa do
        // request) — senão um lançamento Bizify salvo com ERPSERV ativo pegaria company_id errado.
        $folhaCompanyId = \App\Models\Company::where('slug', $empresa)->value('id');
        $saved = 0;

        // Usuários do Raho = linhas 100% editáveis (salvam cpf/nome/status/valor_hora/produção também).
        $rahoId      = self::rahoPartnerId();
        $rahoUserIds = $rahoId ? User::where('partner_id', $rahoId)->pluck('id')->map(fn ($i) => (int) $i)->all() : [];

        // Diretores (têm Fechamento Diretoria no mês) são SOMENTE-LEITURA na folha: identidade
        // vem do cadastro do usuário e valores do Fechamento Diretoria. Ignoramos qualquer
        // edição vinda do grid pra essas linhas (defesa — o FE também não deixa editar).
        $diretoriaUserIds = \App\Models\FechamentoDiretoria::where('year_month', $yearMonth)
            ->pluck('user_id')->map(fn ($i) => (int) $i)->flip();
        // Matrículas dos diretores — Bizify é manual (socio_key/matrícula), sem user_id,
        // então a guarda read-only do diretor na Bizify é por matrícula.
        $diretoriaMatriculas = \App\Models\User::whereIn('id', $diretoriaUserIds->keys())
            ->pluck('matricula')->map(fn ($m) => trim((string) $m))->filter()->flip();

        foreach ($request->input('entries') as $e) {
            if (!empty($e['user_id']) && $diretoriaUserIds->has((int) $e['user_id'])) {
                continue; // linha de diretor: read-only
            }
            // Bizify: linha de diretor é identificada por matrícula (socio_key) → read-only.
            $eMat = trim((string) ($e['matricula'] ?? $e['socio_key'] ?? ''));
            if ($eMat !== '' && $diretoriaMatriculas->has($eMat)) {
                continue;
            }
            $comum = [
                'company_id'         => $folhaCompanyId,
                'dias_trabalhados'   => $e['dias_trabalhados'] ?? 0,
                'horas_trabalhadas'  => $e['horas_trabalhadas'] ?? null,
                'variavel'           => $e['variavel'] ?? 0,
                'reemb'              => $e['reemb'] ?? 0,
                'descontos_diversos' => $e['descontos_diversos'] ?? 0,
                'adiantamento'       => $e['adiantamento'] ?? 0,
                'horista_mensalista' => $e['horista_mensalista'] ?? null,
            ];

            if (!empty($e['socio_key'])) {
                // Linha-sócio/manual (ERPSERV) OU lançamento manual da Bizify: tudo manual.
                FechamentoFolha::updateOrCreate(
                    ['socio_key' => $e['socio_key'], 'year_month' => $yearMonth, 'empresa' => $empresa],
                    array_merge($comum, [
                        'cpf'        => $e['cpf'] ?? null,
                        'matricula'  => $e['matricula'] ?? null,
                        'nome'       => $e['nome'] ?? null,
                        'status'     => $e['status'] ?? null,
                        'valor_hora' => $e['valor_hora'] ?? null,
                        'producao'   => $e['producao'] ?? null,
                        'aj_custo'   => $e['aj_custo'] ?? null,
                        'adto'       => $e['adto'] ?? null,
                    ])
                );
                $saved++;
            } elseif (!empty($e['user_id'])) {
                // Produção é editável p/ TODOS os cooperados (override salvo na folha);
                // valor_hora segue editável só p/ usuários do Raho.
                $extra = ['producao' => $e['producao'] ?? null];
                if (in_array((int) $e['user_id'], $rahoUserIds, true)) {
                    $extra['valor_hora'] = $e['valor_hora'] ?? null;
                }
                FechamentoFolha::updateOrCreate(
                    ['user_id' => $e['user_id'], 'year_month' => $yearMonth, 'empresa' => $empresa],
                    array_merge($comum, $extra)
                );
                $saved++;
            }
        }

        return response()->json(['saved' => $saved]);
    }

    /** Remove uma linha manual (socio_key) do mês. Sócios fixos voltam com defaults; custom somem. */
    public function deleteRow(Request $request, string $yearMonth, string $socioKey): JsonResponse
    {
        if ($r = $this->guard($request)) {
            return $r;
        }
        $empresa = $request->query('empresa') === 'bizify' ? 'bizify' : 'erpserv';
        FechamentoFolha::where('year_month', $yearMonth)->where('socio_key', $socioKey)->where('empresa', $empresa)->delete();
        return response()->json(['deleted' => true]);
    }

    /** Cancela/reativa uma linha do mês (move para/da aba "Canceladas"). */
    public function cancelRow(Request $request, string $yearMonth): JsonResponse
    {
        if ($r = $this->guard($request)) {
            return $r;
        }
        $request->validate([
            'user_id'   => 'nullable|integer|exists:users,id',
            'socio_key' => 'nullable|string|max:60',
            'empresa'   => 'nullable|string|in:erpserv,bizify',
            'cancelado' => 'required|boolean',
        ]);

        $empresa   = $request->input('empresa') === 'bizify' ? 'bizify' : 'erpserv';
        $cancelado = $request->boolean('cancelado');
        if ($request->filled('socio_key')) {
            FechamentoFolha::updateOrCreate(
                ['socio_key' => $request->input('socio_key'), 'year_month' => $yearMonth, 'empresa' => $empresa],
                ['cancelado' => $cancelado]
            );
        } elseif ($request->filled('user_id')) {
            FechamentoFolha::updateOrCreate(
                ['user_id' => $request->integer('user_id'), 'year_month' => $yearMonth, 'empresa' => $empresa],
                ['cancelado' => $cancelado]
            );
        } else {
            return response()->json(['message' => 'Informe user_id ou socio_key.'], 422);
        }

        return response()->json(['cancelado' => $cancelado]);
    }

    /**
     * Importa a planilha da Bizify (xls/xlsx/csv) p/ o mês: MESCLA por matrícula
     * (updateOrCreate — não apaga linhas fora do arquivo). Totais (colunas vermelhas
     * na planilha) são calculados, não importados.
     */
    public function importBizify(Request $request, string $yearMonth): JsonResponse
    {
        if ($r = $this->guard($request)) {
            return $r;
        }
        $request->validate([
            'file' => 'required|file|mimes:xls,xlsx,csv,txt|max:8192',
        ]);

        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($request->file('file')->getRealPath())->getActiveSheet();
        $data  = $sheet->toArray(null, true, false, false);
        if (count($data) < 2) {
            return response()->json(['message' => 'Planilha vazia.'], 422);
        }

        // Mapeia colunas pelo cabeçalho (normalizado: minúsculo, sem acento).
        $norm = function ($s) {
            $s = mb_strtolower(trim((string) $s));
            return strtr($s, ['á'=>'a','â'=>'a','ã'=>'a','à'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c']);
        };
        $header = array_map($norm, $data[0]);
        $col    = fn ($name) => array_search($name, $header, true);

        $cMat  = $col('matricula');
        $cNome = $col('nome');
        $cOper = $col('operacao');
        $cProd = $col('producao');
        $cVar  = $col('variavel');
        $cAjc  = $col('aj custo');
        $cReem = $col('reemb');
        $cAdto = $col('adto');
        $cDesc = $col('descontos');
        $cAdia = $col('adiantamento');

        if ($cMat === false || $cNome === false) {
            return response()->json(['message' => 'Cabeçalho inválido: faltam colunas Matricula/Nome.'], 422);
        }

        $num = function ($v) {
            if ($v === null || $v === '') return 0.0;
            if (is_numeric($v)) return (float) $v;
            $v = str_replace(['.', ' '], '', (string) $v); // pt-BR "8.910,00"
            $v = str_replace(',', '.', $v);
            return is_numeric($v) ? (float) $v : 0.0;
        };

        $imported = 0;
        foreach (array_slice($data, 1) as $row) {
            $mat = trim((string) ($row[$cMat] ?? ''));
            if ($mat === '' || !preg_match('/^\d+$/', $mat)) {
                continue; // pula totais / legenda / linhas sem matrícula numérica
            }
            FechamentoFolha::updateOrCreate(
                ['year_month' => $yearMonth, 'empresa' => 'bizify', 'socio_key' => $mat],
                [
                    'matricula'          => $mat,
                    'nome'               => $cNome !== false ? trim((string) ($row[$cNome] ?? '')) : null,
                    'status'             => $cOper !== false ? trim((string) ($row[$cOper] ?? '')) : null,
                    'producao'           => $cProd !== false ? $num($row[$cProd] ?? 0) : 0,
                    'variavel'           => $cVar  !== false ? $num($row[$cVar]  ?? 0) : 0,
                    'aj_custo'           => $cAjc  !== false ? $num($row[$cAjc]  ?? 0) : 0,
                    'reemb'              => $cReem !== false ? $num($row[$cReem] ?? 0) : 0,
                    'adto'               => $cAdto !== false ? $num($row[$cAdto] ?? 0) : 0,
                    'descontos_diversos' => $cDesc !== false ? $num($row[$cDesc] ?? 0) : 0,
                    'adiantamento'       => $cAdia !== false ? $num($row[$cAdia] ?? 0) : 0,
                ]
            );
            $imported++;
        }

        return response()->json(['imported' => $imported]);
    }

    public function export(Request $request, string $yearMonth)
    {
        if ($r = $this->guard($request)) {
            return $r;
        }
        $empresa = $request->query('empresa') === 'bizify' ? 'bizify' : 'erpserv';
        [$year, $month] = explode('-', $yearMonth);

        // O .xls não leva linhas canceladas (elas estão na aba "Canceladas").
        $rows = array_values(array_filter($this->buildRows($yearMonth, $empresa), fn ($r) => empty($r['cancelado'])));

        // As DUAS empresas usam o mesmo layout (cadastro/cooperados) — só muda o nome do arquivo.
        $fileName = $empresa === 'bizify'
            ? "{$month}_{$year}_BIZIFY SOLUCOES TECNOLOGICAS LTDA.xls"
            : "{$month}_{$year}_M_ERPSERV CONSULTORIA DE SISTEMAS LTDA.xls";
        return Excel::download(new FolhaPagamentoExport($rows), $fileName, ExcelType::XLS);
    }
}
