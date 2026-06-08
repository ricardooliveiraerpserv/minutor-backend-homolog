<?php

namespace App\Notifications;

use App\Models\HourContribution;
use App\Models\Project;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Comunica ao CLIENTE que um novo aporte de horas foi registrado em um
 * contrato/projeto existente. Disparado no POST de aporte (store), apenas
 * quando motivo = 'aporte' (excedentes/absorvidas são ajustes internos).
 *
 * Síncrona (sem ShouldQueue) — segue o padrão de ProjectFromContractGenerated:
 * evento de baixa frequência, garante entrega sem depender de queue-worker.
 */
class ContractAporteNotification extends Notification
{
    public HourContribution $contribution;
    public Project $project;
    /** @var array<int, string> Cópia (Cc): executivo de contas + quem incluiu o aporte. */
    public array $cc;

    public function __construct(HourContribution $contribution, Project $project, array $cc = [])
    {
        $this->contribution = $contribution;
        $this->project      = $project;
        $this->cc           = $cc;
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $p = $this->project->loadMissing(['customer:id,name']);
        $codigo  = $p->code ?? '—';
        $projeto = $p->name ?? '—';
        $cliente = optional($p->customer)->name ?? '—';

        $horas = number_format((float) $this->contribution->contributed_hours, 2, ',', '.');
        $data  = optional($this->contribution->contributed_at)->format('d/m/Y') ?? now()->format('d/m/Y');

        // Saldo total de horas do contrato APÓS o aporte (já reflete a contribuição recém-criada).
        // Recarrega do banco pra não usar relação de aportes em cache (saldo inflado/defasado).
        // On Demand não controla saldo → getGeneralHoursBalance devolve 0; nesse caso oculta a linha.
        $saldoFmt = null;
        try {
            $fresh = Project::find($p->id) ?? $p;
            if (!$fresh->isOnDemand()) {
                $saldoFmt = number_format($fresh->getGeneralHoursBalance(), 2, ',', '.') . ' h';
            }
        } catch (\Throwable $e) {
            $saldoFmt = null;
        }

        $base = rtrim((string) config('app.frontend_url', 'https://app.minutor.com.br'), '/');

        $mail = (new MailMessage)
            ->subject("[Minutor] Novo aporte de horas no contrato — {$codigo}");

        // Cópia: executivo de contas + quem incluiu o aporte.
        if (!empty($this->cc)) {
            $mail->cc($this->cc);
        }

        return $mail
            ->view('emails.contracts.aporte', [
                'codigo'        => $codigo,
                'projeto'       => $projeto,
                'cliente'       => $cliente,
                'horas'         => $horas,
                'saldo'         => $saldoFmt,
                'data'          => $data,
                'cardUrl'       => $base,
                'recipientName' => $notifiable->name ?? 'cliente',
            ]);
    }
}
