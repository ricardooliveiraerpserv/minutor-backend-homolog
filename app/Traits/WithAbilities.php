<?php

namespace App\Traits;

use App\Models\User;
use App\Services\AccessControl;

/**
 * Anexa flags de ação por LINHA ao serializar um modelo, para o frontend desabilitar botões
 * e mostrar o motivo — sem chamar can() por linha no FE. Usado pelo Timesheet (apontamentos).
 */
trait WithAbilities
{
    /** @return array{can_edit: bool, can_delete: bool, reason_edit: ?string, reason_delete: ?string} */
    public function abilitiesFor(User $user): array
    {
        $edit = AccessControl::decide($user, 'update', $this);
        $del  = AccessControl::decide($user, 'delete', $this);

        return [
            'can_edit'      => $edit['allowed'],
            'can_delete'    => $del['allowed'],
            'reason_edit'   => $edit['reason'],
            'reason_delete' => $del['reason'],
        ];
    }
}
