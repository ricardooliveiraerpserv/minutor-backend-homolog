<?php

namespace App\Notifications;

use App\Models\Project;
use App\Notifications\Concerns\HasWorkflowCc;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifica a movimentação de um projeto para uma fase terminal
 * (Fechado / Cancelado / Pausado), pelos workflows configuráveis
 * `project.finished` / `project.cancelled` / `project.paused` na Central.
 *
 * Síncrono (sem ShouldQueue) — evento raro, evita depender de queue-worker.
 */
class ProjectPhaseChangedNotification extends Notification
{
    use HasWorkflowCc;

    /** Metadados de exibição por workflow de fase. */
    private const META = [
        'project.finished'  => ['tag' => 'Projeto encerrado', 'verb' => 'foi encerrado', 'badge' => 'ENCERRADO', 'accent' => '#94A3B8'],
        'project.cancelled' => ['tag' => 'Projeto cancelado', 'verb' => 'foi cancelado', 'badge' => 'CANCELADO', 'accent' => '#EF4444'],
        'project.paused'    => ['tag' => 'Projeto pausado',  'verb' => 'foi pausado',  'badge' => 'PAUSADO',   'accent' => '#EAB308'],
    ];

    public Project $project;
    public string $workflowKey;

    public function __construct(Project $project, string $workflowKey)
    {
        $this->project     = $project;
        $this->workflowKey = $workflowKey;
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $p = $this->project->loadMissing(['customer:id,name']);
        $meta = self::META[$this->workflowKey] ?? self::META['project.finished'];

        $codigo  = $p->code ?: '—';
        $projeto = $p->name ?: '—';
        $cliente = optional($p->customer)->name ?? '—';

        $base    = rtrim((string) config('app.frontend_url', 'https://app.minutor.com.br'), '/');
        $cardUrl = "{$base}/projetos/{$p->id}";

        $recipientName = $notifiable instanceof AnonymousNotifiable
            ? 'equipe'
            : ($notifiable->name ?? 'equipe');

        return $this->applyCc((new MailMessage)
            ->subject("[Minutor] {$meta['tag']} — {$codigo}"))
            ->view('emails.contracts.project-phase', [
                'accent'        => app(\App\Workflows\WorkflowConfigService::class)->accent($this->workflowKey),
                'tag'           => $meta['tag'],
                'verb'          => $meta['verb'],
                'badge'         => $meta['badge'],
                'codigo'        => $codigo,
                'projeto'       => $projeto,
                'cliente'       => $cliente,
                'cardUrl'       => $cardUrl,
                'recipientName' => $recipientName,
            ]);
    }
}
