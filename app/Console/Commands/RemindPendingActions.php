<?php

namespace App\Console\Commands;

use App\Http\Controllers\ActionReminderController;
use App\Http\Controllers\NotificationController;
use App\Models\ActionReminderRule;
use App\Models\AppNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Re-lembra as AÇÕES NÃO RESOLVIDAS na cadência definida pela rotina (admin). Para cada regra
 * habilitada que está "na hora" (a cada X horas/dias), reabre o pop-up + reenvia o e-mail aos
 * usuários que ainda têm a pendência. Roda de hora em hora; cada regra decide se dispara.
 */
class RemindPendingActions extends Command
{
    protected $signature = 'actions:remind-pending {--date= : Data de referência p/ teste}';
    protected $description = 'Lembra ações não resolvidas conforme a recorrência configurada';

    public function handle(NotificationController $ctrl): int
    {
        $now = $this->option('date') ? Carbon::parse($this->option('date')) : now();
        $fired = 0;

        foreach (ActionReminderRule::all() as $rule) {
            if (!$rule->isDue($now)) continue;

            $userIds = ActionReminderController::affectedUserIds($rule->key);
            $d = ActionReminderRule::DEFAULTS[$rule->key] ?? null;

            if (!empty($userIds) && $d) {
                $attrs = [
                    'title'        => $d['title'],
                    'message'      => 'Lembrete: há itens pendentes aguardando sua ação.',
                    'type'         => 'action',
                    'priority'     => $d['priority'],
                    'requires_ack' => false,
                    'cta_label'    => $d['cta_label'],
                    'cta_url'      => $d['cta_url'],
                    'target_users' => array_values(array_map('intval', $userIds)),
                    'target_roles' => null,
                    'send_email'   => true,
                    'visible'      => true,
                    'is_template'  => false,
                    'expires_at'   => $now->copy()->endOfDay(),
                ];

                $n = $rule->notification_id ? AppNotification::find($rule->notification_id) : null;
                if ($n) {
                    $n->forceFill($attrs)->save();
                } else {
                    $n = AppNotification::create($attrs);
                    $rule->notification_id = $n->id;
                }

                // Associação à Central de Workflows: inclui as cópias configuradas lá (extra e-mails + papéis).
                $extraBcc = [];
                if (!empty($d['workflow'])) {
                    $r = app(\App\Workflows\WorkflowRecipientResolver::class)->resolve($d['workflow']);
                    $extraBcc = array_merge($r['to'] ?? [], $r['cc'] ?? []);
                }

                $ctrl->fire($n->fresh(), $extraBcc);   // zera leituras (reabre pop-up) + reenvia e-mail (+cópias da Central)
                $fired++;
                $this->info("lembrete '{$rule->key}' → " . count($userIds) . ' usuário(s)');
            }

            $rule->last_fired_at = $now;
            $rule->save();
        }

        $this->info("lembretes disparados: {$fired}");
        return self::SUCCESS;
    }
}
