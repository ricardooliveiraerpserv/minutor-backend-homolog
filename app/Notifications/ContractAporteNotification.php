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

    public function __construct(HourContribution $contribution, Project $project)
    {
        $this->contribution = $contribution;
        $this->project      = $project;
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

        $base = rtrim((string) config('app.frontend_url', 'https://app.minutor.com.br'), '/');

        return (new MailMessage)
            ->subject("[Minutor] Novo aporte de horas no contrato — {$codigo}")
            ->view('emails.contracts.aporte', [
                'codigo'        => $codigo,
                'projeto'       => $projeto,
                'cliente'       => $cliente,
                'horas'         => $horas,
                'data'          => $data,
                'cardUrl'       => $base,
                'recipientName' => $notifiable->name ?? 'cliente',
            ]);
    }
}
