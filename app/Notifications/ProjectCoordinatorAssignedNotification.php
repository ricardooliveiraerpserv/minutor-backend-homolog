<?php

namespace App\Notifications;

use App\Models\Contract;
use App\Models\Project;
use App\Notifications\Concerns\HasWorkflowCc;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifica o coordenador quando ele é atribuído (ou trocado) num projeto JÁ
 * gerado — disparado ao mover o card para a coluna do coordenador no kanban
 * de contratos. Diferente de ProjectFromContractGeneratedNotification (que é o
 * broadcast da geração do projeto): este é direcionado só ao coordenador, para
 * não reincomodar cliente/executivo/contatos a cada reatribuição.
 *
 * Síncrono (sem ShouldQueue) — evento raro, evita depender de queue-worker.
 */
class ProjectCoordinatorAssignedNotification extends Notification
{
    use HasWorkflowCc;

    public Contract $contract;
    public Project $project;

    public function __construct(Contract $contract, Project $project)
    {
        $this->contract = $contract;
        $this->project  = $project;
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $c = $this->contract->loadMissing(['customer:id,name']);
        $codigo  = ($this->project->code ?? null) ?: ($c->project_code_preview ?? null) ?: '—';
        $projeto = $this->project->name ?? ($c->project_name ?? '—');
        $cliente = optional($c->customer)->name ?? '—';

        $base = rtrim((string) config('app.frontend_url', 'https://app.minutor.com.br'), '/');
        $cardUrl = "{$base}/projetos/{$this->project->id}";

        $recipientName = $notifiable instanceof AnonymousNotifiable
            ? 'coordenador(a)'
            : ($notifiable->name ?? 'coordenador(a)');

        return $this->applyCc((new MailMessage)
            ->subject("[Minutor] Você foi designado(a) coordenador(a) — {$codigo}"))
            ->view('emails.contracts.coordinator-assigned', [
                'accent'        => app(\App\Workflows\WorkflowConfigService::class)->accent('project.coordinator_assigned'),
                'codigo'        => $codigo,
                'projeto'       => $projeto,
                'cliente'       => $cliente,
                'cardUrl'       => $cardUrl,
                'recipientName' => $recipientName,
            ]);
    }
}
