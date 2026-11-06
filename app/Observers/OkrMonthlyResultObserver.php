<?php

namespace App\Observers;

use App\Models\OkrMonthlyResult;
use App\Models\OkrResultChangeLog;
use Illuminate\Support\Facades\Auth;

/**
 * Registra o histórico de alterações do realizado mensal (valor antigo/novo,
 * quem, quando). Espelha ProjectObserver. Sem usuário autenticado (CLI/seed),
 * não registra.
 */
class OkrMonthlyResultObserver
{
    /** Campos auditados. */
    private array $trackedFields = ['actual_value', 'situation'];

    public function updated(OkrMonthlyResult $result): void
    {
        $userId = Auth::id();
        if (!$userId) {
            return;
        }

        foreach ($this->trackedFields as $field) {
            if ($result->wasChanged($field)) {
                OkrResultChangeLog::create([
                    'okr_key_result_id' => $result->okr_key_result_id,
                    'year_month' => $result->year_month,
                    'field_name' => $field,
                    'old_value' => $this->stringify($result->getOriginal($field)),
                    'new_value' => $this->stringify($result->$field),
                    'changed_by' => $userId,
                    'reason' => $result->changeReason ?? null,
                    'created_at' => now(),
                ]);
            }
        }
    }

    private function stringify(mixed $v): ?string
    {
        return $v === null ? null : (string) $v;
    }
}
