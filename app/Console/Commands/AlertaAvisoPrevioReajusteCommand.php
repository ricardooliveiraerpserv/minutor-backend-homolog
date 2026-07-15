<?php

namespace App\Console\Commands;

use App\Http\Controllers\ContractController;
use App\Mail\ReajustesPendentesMail;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Aviso prévio de reajuste — 1 mês antes do vencimento avisa o ADMINISTRATIVO
 * para ele enviar ao cliente o comunicado de "reajuste no próximo mês".
 * NÃO envia nada ao cliente: só alerta o administrativo. Destinatários pela
 * Central de Workflows (contract.reajuste.aviso). Roda diário; dispara nos
 * contratos cujo "data de aviso" (vencimento − 1 mês) cai hoje.
 */
class AlertaAvisoPrevioReajusteCommand extends Command
{
    protected $signature = 'reajustes:alerta-aviso-previo {--force : Ignora a janela e lista tudo em aviso}';

    protected $description = 'Avisa o administrativo (1 mês antes do vencimento) para enviar o comunicado de reajuste';

    public function handle(): int
    {
        $hoje = Carbon::today();
        $rows = app(ContractController::class)->reajustesData()
            ->filter(function ($r) use ($hoje) {
                if (empty($r['data_aviso'])) return false;
                $aviso = Carbon::parse($r['data_aviso']);
                return $this->option('force')
                    ? ($aviso->lte($hoje) && ($r['status_reajuste'] ?? '') !== 'recente')
                    : $aviso->isSameDay($hoje);
            })
            ->values();

        if ($rows->isEmpty()) {
            $this->info('Nenhum contrato entrando na janela de aviso hoje.');
            return self::SUCCESS;
        }

        $totalImpacto = round((float) $rows->sum('valor_estimado_reajuste'), 2);
        $referencia   = ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'][$hoje->month - 1] . '/' . $hoje->year;
        $dashboardUrl = rtrim(env('APP_FRONTEND_URL', config('app.url', 'https://app.minutor.com.br')), '/') . '/fechamento/reajustes';

        $rcpt = app(\App\Workflows\WorkflowRecipientResolver::class)->resolve('contract.reajuste.aviso', []);
        $to = $rcpt['to'];
        $cc = $rcpt['cc'];
        if (empty($to)) {
            $this->warn('Sem destinatários configurados na Central (contract.reajuste.aviso). Nada enviado.');
            return self::SUCCESS;
        }

        $mail = new ReajustesPendentesMail($rows->all(), $totalImpacto, $referencia, $dashboardUrl);
        $graphFrom = config('services.graph.mailbox');
        if (\App\Services\GraphMailer::enabled() && $graphFrom) {
            \App\Services\GraphMailer::sendAs($graphFrom, $to, $cc, 'Aviso prévio de reajuste — enviar comunicado aos clientes', $mail->render());
        } else {
            Mail::to($to)->cc($cc)->send($mail);
        }

        $this->info('Aviso prévio enviado a ' . implode(', ', $to) . ": {$rows->count()} contrato(s) na janela.");
        return self::SUCCESS;
    }
}
