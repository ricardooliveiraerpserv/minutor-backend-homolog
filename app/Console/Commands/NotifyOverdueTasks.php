<?php

namespace App\Console\Commands;

use App\Models\AppNotification;
use App\Models\Task;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Avisa o RESPONSÁVEL (assigned_to) sobre tarefas ATRASADAS (não concluídas, due_date < hoje).
 * Cria/atualiza 1 notificação por usuário/dia na Central (pop-up + CTA p/ a tela inicial).
 */
class NotifyOverdueTasks extends Command
{
    protected $signature = 'tasks:notify-overdue {--date= : Data de referência YYYY-MM-DD (p/ teste)}';
    protected $description = 'Notifica o responsável sobre suas tarefas atrasadas';

    public function handle(): int
    {
        $today = $this->option('date') ? Carbon::parse($this->option('date'))->startOfDay() : now()->startOfDay();

        $counts = Task::where('completed', false)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $today->toDateString())
            ->whereNotNull('assigned_to')
            ->selectRaw('assigned_to, count(*) as c')
            ->groupBy('assigned_to')
            ->pluck('c', 'assigned_to');

        $n = 0;
        foreach ($counts as $uid => $count) {
            $msg = "Você tem {$count} tarefa(s) atrasada(s). Conclua o quanto antes.";
            $existing = AppNotification::where('title', 'Tarefas atrasadas')
                ->whereJsonContains('target_users', (int) $uid)
                ->whereDate('created_at', $today->toDateString())->first();

            if ($existing) {
                $existing->update(['message' => $msg, 'resent_at' => now(), 'expires_at' => $today->copy()->endOfDay()]);
            } else {
                AppNotification::create([
                    'title'        => 'Tarefas atrasadas',
                    'message'      => $msg,
                    'type'         => 'action',
                    'priority'     => 'high',
                    'target_users' => [(int) $uid],
                    'cta_label'    => 'Ver tarefas',
                    'cta_url'      => '/inicio',
                    'visible'      => true,
                    'send_email'   => false,
                    'requires_ack' => false,
                    'created_by'   => (int) $uid,
                    'resent_at'    => now(),
                    'expires_at'   => $today->copy()->endOfDay(),
                ]);
            }
            $n++;
        }

        $this->info("tarefas atrasadas: {$n} usuário(s) notificado(s)");
        return self::SUCCESS;
    }
}
