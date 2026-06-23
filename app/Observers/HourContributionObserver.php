<?php

namespace App\Observers;

use App\Models\HourContribution;
use App\Models\HourContributionChangeLog;
use Illuminate\Support\Facades\Auth;

class HourContributionObserver
{
    /**
     * Campos sensíveis do aporte rastreados para auditoria.
     *
     * @var array<string>
     */
    private array $trackedFields = [
        'hourly_rate',
        'contributed_hours',
        'contributed_at',
    ];

    /**
     * Handle the HourContribution "updated" event.
     * Registra old→new de cada campo sensível que mudou.
     */
    public function updated(HourContribution $contribution): void
    {
        $userId = Auth::id();

        // Vigência do aporte = competência (contributed_at) no momento da mudança.
        $effectiveFrom = optional($contribution->contributed_at)->copy()->startOfMonth()->toDateString();

        // Motivo opcional, repassado pelo controller via atributo transitório.
        $reason = $contribution->changeReason ?? null;

        foreach ($this->trackedFields as $field) {
            if (!$contribution->wasChanged($field)) {
                continue;
            }

            $old = $contribution->getOriginal($field);
            $new = $contribution->$field;

            // Datas viram string normalizada (evita ruído de horário).
            if ($field === 'contributed_at') {
                $old = $old ? \Illuminate\Support\Carbon::parse($old)->toDateString() : null;
                $new = $new ? \Illuminate\Support\Carbon::parse($new)->toDateString() : null;
                if ($old === $new) {
                    continue;
                }
            }

            HourContributionChangeLog::create([
                'hour_contribution_id' => $contribution->id,
                'project_id'           => $contribution->project_id,
                'changed_by'           => $userId,
                'field_name'           => $field,
                'old_value'            => $old,
                'new_value'            => $new,
                'reason'               => $reason,
                'effective_from'       => $effectiveFrom,
            ]);
        }
    }

    /**
     * Ao excluir o aporte (soft delete), registra o evento para rastro.
     */
    public function deleted(HourContribution $contribution): void
    {
        $userId = Auth::id();
        $effectiveFrom = optional($contribution->contributed_at)->copy()->startOfMonth()->toDateString();

        HourContributionChangeLog::create([
            'hour_contribution_id' => $contribution->id,
            'project_id'           => $contribution->project_id,
            'changed_by'           => $userId,
            'field_name'           => 'deleted',
            'old_value'            => $contribution->contributed_hours . 'h x ' . $contribution->hourly_rate,
            'new_value'            => null,
            'reason'               => $contribution->changeReason ?? null,
            'effective_from'       => $effectiveFrom,
        ]);
    }
}
