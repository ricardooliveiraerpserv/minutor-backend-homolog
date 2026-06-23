<?php

namespace App\Console\Commands;

use App\Models\Expense;
use App\Workflows\WorkflowConfigService;
use App\Workflows\WorkflowMailer;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Recorrência do aviso de "despesa aprovada — pendente de pagamento".
 * Roda diariamente: para cada despesa APROVADA e NÃO paga, reenvia o aviso a cada
 * N dias (N = recurrence_days configurado na Central). A contagem parte do último
 * aviso enviado (ou da aprovação, se nunca houve recorrente). N = 0 → desligado.
 */
class AlertaDespesasPendentesPagamentoCommand extends Command
{
    protected $signature = 'expenses:alerta-pendentes-pagamento {--force : Ignora o intervalo e reenvia agora}';

    protected $description = 'Reenvia aviso de despesas aprovadas e não pagas conforme a recorrência da Central';

    private const WORKFLOW_KEY = 'expense.approved_pending_payment';

    public function handle(WorkflowConfigService $config, WorkflowMailer $mailer): int
    {
        $days = (int) ($config->template(self::WORKFLOW_KEY)['recurrence_days'] ?? 0);

        if ($days <= 0 && !$this->option('force')) {
            $this->info('Recorrência desligada (recurrence_days = 0). Nada a enviar.');
            return self::SUCCESS;
        }

        $now = Carbon::now();
        $force = (bool) $this->option('force');

        $pendentes = Expense::query()
            ->where('status', Expense::STATUS_APPROVED)
            ->where('is_paid', false)
            ->get();

        $enviadas = 0;
        foreach ($pendentes as $expense) {
            $baseline = $expense->payment_alert_last_sent_at ?? $expense->reviewed_at;

            // Sem intervalo cumprido ainda → pula (a menos que --force).
            if (!$force) {
                if (!$baseline) {
                    continue;
                }
                if ($now->lt(Carbon::parse($baseline)->addDays($days))) {
                    continue;
                }
            }

            $ok = $mailer->send(
                self::WORKFLOW_KEY,
                ['actor' => null],
                $expense->paymentWorkflowVars(),
            );

            if ($ok) {
                $expense->forceFill(['payment_alert_last_sent_at' => $now])->saveQuietly();
                $enviadas++;
            }
        }

        $this->info("Avisos recorrentes enviados: {$enviadas} (de {$pendentes->count()} despesa(s) aprovada(s) não paga(s)).");
        return self::SUCCESS;
    }
}
