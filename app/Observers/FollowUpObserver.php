<?php

namespace App\Observers;

use App\Models\FollowUp;
use App\Models\FollowUpEvent;
use Illuminate\Support\Facades\Auth;

/**
 * Auditoria automática do Follow Up (espelha StageDeliveryObserver):
 * registra criação, mudança de status (incl. waiting_third → pausa/retoma SLA),
 * reatribuição, mudança de prazo, conclusão e reabertura.
 */
class FollowUpObserver
{
    public function created(FollowUp $f): void
    {
        $this->event($f, FollowUpEvent::TYPE_CREATED, [
            'title'    => $f->title,
            'status'   => $f->status,
            'priority' => $f->priority,
        ]);
    }

    public function updating(FollowUp $f): void
    {
        // Mantém sla_paused_at coerente com o status (antes de salvar).
        if ($f->isDirty('status')) {
            if ($f->status === FollowUp::STATUS_WAITING_THIRD && !$f->sla_paused_at) {
                $f->sla_paused_at = now();
            } elseif ($f->status !== FollowUp::STATUS_WAITING_THIRD && $f->sla_paused_at) {
                $f->sla_paused_at = null;
            }

            // Carimba conclusão.
            if ($f->status === FollowUp::STATUS_COMPLETED && !$f->completed_at) {
                $f->completed_at = now();
                $f->completed_by = Auth::id();
            }
            if (in_array($f->getOriginal('status'), [FollowUp::STATUS_COMPLETED, FollowUp::STATUS_CANCELLED], true)
                && !in_array($f->status, [FollowUp::STATUS_COMPLETED, FollowUp::STATUS_CANCELLED], true)) {
                $f->completed_at = null;
                $f->completed_by = null;
            }
        }
    }

    public function updated(FollowUp $f): void
    {
        if ($f->wasChanged('status')) {
            $from = $f->getOriginal('status');
            $to   = $f->status;

            // Um único evento por transição (tipo deriva do destino).
            $type = match (true) {
                $to === FollowUp::STATUS_WAITING_THIRD                              => FollowUpEvent::TYPE_WAITING_SET,
                $to === FollowUp::STATUS_COMPLETED                                  => FollowUpEvent::TYPE_CONCLUDED,
                in_array($from, [FollowUp::STATUS_COMPLETED, FollowUp::STATUS_CANCELLED], true) => FollowUpEvent::TYPE_REOPENED,
                $from === FollowUp::STATUS_WAITING_THIRD                            => FollowUpEvent::TYPE_WAITING_CLEARED,
                default                                                            => FollowUpEvent::TYPE_STATUS_CHANGED,
            };
            $this->event($f, $type, ['from' => $from, 'to' => $to, 'subtype' => $f->waiting_subtype]);
        }

        if ($f->wasChanged('responsible_user_id')) {
            $this->event($f, FollowUpEvent::TYPE_REASSIGNED, [
                'from' => $f->getOriginal('responsible_user_id'),
                'to'   => $f->responsible_user_id,
            ]);
        }

        if ($f->wasChanged('due_date')) {
            $orig = $f->getOriginal('due_date');
            $this->event($f, FollowUpEvent::TYPE_DEADLINE_CHANGED, [
                'from' => $orig ? substr((string) $orig, 0, 10) : null,
                'to'   => optional($f->due_date)->toDateString(),
            ]);
        }
    }

    private function event(FollowUp $f, string $type, array $payload): void
    {
        FollowUpEvent::create([
            'follow_up_id'  => $f->id,
            'actor_user_id' => Auth::id(),
            'type'          => $type,
            'payload'       => array_filter($payload, fn ($v) => $v !== null),
        ]);
    }
}
