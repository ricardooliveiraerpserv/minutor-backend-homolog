<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use App\Notifications\ChatMentionNotification;
use App\Workflows\WorkflowRecipientResolver;
use Illuminate\Support\Facades\Notification;

/**
 * Dispara o workflow `chat.mention` para as pessoas marcadas (@) numa mensagem
 * de chat (requisição / contrato / projeto). Compartilhado pelos 3 controllers
 * de mensagem. Exclui o autor e quem já recebeu o e-mail de "Mensagem no chat"
 * (envolvidos), evitando e-mail duplicado.
 */
trait DispatchesChatMentions
{
    /**
     * @param  string    $cardType      tipo do card ('contract_request'|'project'|'contract')
     * @param  int       $cardId        id do card
     * @param  User      $author        autor da mensagem
     * @param  int[]     $mentionedIds  ids marcados (@) na mensagem
     * @param  array     $payload       ['code','title','role','excerpt','openUrl','cardUrl']
     * @param  string[]  $already       e-mails que já receberam (não duplicar)
     */
    protected function dispatchMentionNotification(string $cardType, int $cardId, User $author, array $mentionedIds, array $payload, array $already = []): void
    {
        $mentionedIds = array_values(array_filter($mentionedIds, fn ($id) => (int) $id !== (int) $author->id));
        if (empty($mentionedIds)) return;

        $rcpt = app(WorkflowRecipientResolver::class)->resolve('chat.mention', [
            'card'      => ['type' => $cardType, 'id' => $cardId],
            'actor'     => $author,
            'mentioned' => $mentionedIds,
        ]);
        $to = array_values(array_diff($rcpt['to'] ?? [], $already));
        if (empty($to)) return;

        Notification::route('mail', $to)->notify(new ChatMentionNotification(
            cardType:       $cardType,
            cardCode:       (string) ($payload['code'] ?? '—'),
            cardTitle:      (string) ($payload['title'] ?? ''),
            authorName:     $author->name,
            authorRole:     (string) ($payload['role'] ?? 'Equipe'),
            messageExcerpt: (string) ($payload['excerpt'] ?? ''),
            openUrl:        (string) ($payload['openUrl'] ?? ''),
            cardUrl:        (string) ($payload['cardUrl'] ?? ''),
            customerName:   (string) ($payload['customer'] ?? ''),
        ));
    }
}
