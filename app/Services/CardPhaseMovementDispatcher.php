<?php

namespace App\Services;

use App\Models\CardEnvolvido;
use App\Models\ContractRequest;
use App\Models\Project;
use App\Models\User;
use App\Notifications\CardPhaseMovementNotification;
use Illuminate\Support\Facades\Notification;

class CardPhaseMovementDispatcher
{
    public function __construct(private CardEnvolvidoService $envolvidoSvc) {}

    /**
     * Dispara notificação de movimentação para todos os envolvidos do card.
     * Cliente recebe sempre (mesmo após req_decided_at).
     */
    public function dispatch(
        string $cardType,
        int $cardId,
        string $fromColumn,
        string $toColumn,
        User $movedBy,
        ?string $note = null,
    ): void {
        // Se from == to, nada a notificar
        if ($fromColumn === $toColumn) return;

        // Destinatários pela Central de Workflows (split por tipo de card: requisição vs projeto).
        $workflowKey = $cardType === CardEnvolvido::TYPE_REQUEST
            ? 'card.phase_movement.request'
            : 'card.phase_movement.project';
        $rcpt = app(\App\Workflows\WorkflowRecipientResolver::class)->resolve($workflowKey, [
            'card'  => ['type' => $cardType, 'id' => $cardId],
            'actor' => $movedBy,
        ]);
        if (empty($rcpt['to'])) return;

        [$code, $title, $cardUrl] = $this->resolveCardInfo($cardType, $cardId);

        Notification::route('mail', $rcpt['to'])->notify(
            (new CardPhaseMovementNotification(
                cardType:       $cardType,
                cardCode:       $code,
                cardTitle:      $title,
                fromColumn:     $fromColumn,
                toColumn:       $toColumn,
                movedByName:    $movedBy->name,
                movedByRole:    $this->userRoleLabel($movedBy),
                note:           $note,
                cardUrl:        $cardUrl,
                recipientName:  'você',
            ))->withCc($rcpt['cc'])
        );
    }

    /**
     * @return array{0:string,1:string,2:string} [code, title, cardUrl]
     */
    private function resolveCardInfo(string $cardType, int $cardId): array
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        if ($cardType === CardEnvolvido::TYPE_REQUEST) {
            $r = ContractRequest::find($cardId);
            $code = $r?->code ?? ('REQ-' . str_pad((string) $cardId, 6, '0', STR_PAD_LEFT));
            $title = $r?->title ?? ($r?->subject ?? 'Requisição');
            return [$code, $title, $base . '/contratos/pipeline?req=' . $cardId];
        }

        $p = Project::find($cardId);
        $code = $p?->code ?? ('PRJ-' . str_pad((string) $cardId, 6, '0', STR_PAD_LEFT));
        $title = $p?->name ?? 'Projeto';
        return [$code, $title, $base . '/contratos/pipeline?project=' . $cardId];
    }

    private function userRoleLabel(User $u): string
    {
        return match ($u->type) {
            'admin' => 'Admin', 'coordenador' => 'Coordenador', 'consultor' => 'Consultor',
            'cliente' => 'Cliente', 'parceiro_admin' => 'Parceiro', 'administrativo' => 'Administrativo',
            default => 'Equipe',
        };
    }
}
