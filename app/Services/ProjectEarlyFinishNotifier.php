<?php

namespace App\Services;

use App\Mail\ProjectEarlyFinishMail;
use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * Saving de finalização antecipada: quando o projeto é finalizado ANTES do prazo
 * (expected_end_date), informa dias de antecedência + horas economizadas + resumo
 * por e-mail ao coordenador, executivo da conta e diretor de projetos (Badawi).
 */
class ProjectEarlyFinishNotifier
{
    /** Dados do saving, ou null se NÃO foi finalizado antes do prazo. */
    public function earlyFinishData(Project $p): ?array
    {
        if ($p->status !== Project::STATUS_FINISHED) return null;

        $prazo = $p->expected_end_date ? Carbon::parse($p->expected_end_date)->startOfDay() : null;
        if (!$prazo) return null;

        $finish = $p->encerramento_date
            ? Carbon::parse($p->encerramento_date)->startOfDay()
            : Carbon::now()->startOfDay();

        if (!$finish->lt($prazo)) return null; // não foi antecipado

        $p->loadMissing(['coordinators:id,name,email', 'customer:id,name,executive_id', 'customer.executive:id,name,email']);

        return [
            'project'       => $p,
            'prazo'         => $prazo->toDateString(),
            'encerramento'  => $finish->toDateString(),
            'days_early'    => (int) $finish->diffInDays($prazo),
            'hours_saved'   => round($p->getGeneralHoursBalance(), 2),
            'coordenadores' => $p->coordinators->pluck('name')->implode(', '),
        ];
    }

    /** Destinatários: coordenadores + executivo da conta + diretor de projetos. */
    public function recipients(Project $p): array
    {
        $emails = [];
        foreach ($p->coordinators as $c) {
            if ($c->email) $emails[] = $c->email;
        }
        $exec = optional(optional($p->customer)->executive);
        if ($exec && $exec->email) $emails[] = $exec->email;

        $diretor = config('services.diretor_projetos_email');
        if ($diretor) $emails[] = $diretor;

        return array_values(array_unique(array_filter($emails)));
    }

    /**
     * Envia o e-mail de saving. Retorna ['sent'=>bool, 'reason'?, 'to'?, ...].
     * $force ignora o saving_notified_at (reenvio manual).
     */
    public function send(Project $p, bool $force = false): array
    {
        $data = $this->earlyFinishData($p);
        if (!$data) {
            return ['sent' => false, 'reason' => 'O projeto não foi finalizado antes do prazo.'];
        }
        if (!$force && $p->saving_notified_at) {
            return ['sent' => false, 'reason' => 'Saving já notificado.'];
        }

        $to = $this->recipients($p);
        if (empty($to)) {
            return ['sent' => false, 'reason' => 'Nenhum destinatário (coordenador/executivo/diretor).'];
        }

        Mail::to($to)->send(new ProjectEarlyFinishMail($data));
        $p->forceFill(['saving_notified_at' => now()])->saveQuietly();

        return ['sent' => true, 'to' => $to, 'days_early' => $data['days_early'], 'hours_saved' => $data['hours_saved']];
    }
}
