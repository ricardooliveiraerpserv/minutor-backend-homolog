<?php

namespace App\Services;

use App\Exceptions\HelpDeskMergeException;
use App\Models\HelpDeskTicket;
use App\Models\HelpDeskTicketComment;
use App\Models\HelpDeskTicketEvent;
use App\Models\HelpDeskTicketMerge;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Mesclagem e desmesclagem de chamados do Help Desk.
 *
 * MODELO: mesclar MOVE as interações (comments) do chamado de ORIGEM para o de DESTINO. Cada comment
 * guarda `origin_ticket_id` = onde foi criado — é isso que permite DESMESCLAR corretamente: só as
 * interações com origin = origem voltam pra ela; as criadas depois (no contexto do destino) ficam.
 * O chamado de origem NÃO é excluído: fica com `merged_into_id` setado (some das listagens, mas segue
 * visível/consultável na seção "Tickets Mesclados" do destino). Tudo transacional, com lock e log.
 */
class HelpDeskMergeService
{
    /** Teto (parametrizável) de interações somadas entre os chamados a mesclar. */
    public function maxActions(): int
    {
        return max(1, (int) config('helpdesk.merge.max_actions', 500));
    }

    /** Chamado mesclável: tem status e ele é ABERTO — nem terminal (fechado/cancelado) nem resolvido. */
    public function isActive(HelpDeskTicket $t): bool
    {
        $t->loadMissing('status');
        return $t->status !== null && !$t->status->is_terminal && !$t->status->is_resolved;
    }

    /**
     * Mescla um ou mais chamados de ORIGEM num chamado de DESTINO.
     *
     * @param array<int,HelpDeskTicket> $sources
     * @param array{keep_requesters?:bool,keep_cc?:bool,keep_tags?:bool} $options
     */
    public function merge(HelpDeskTicket $target, array $sources, array $options, ?User $user): HelpDeskTicket
    {
        // Normaliza: dedup por id e remove o próprio destino da lista de origens.
        $sources = collect($sources)->unique('id')->reject(fn (HelpDeskTicket $s) => $s->id === $target->id)->values();
        if ($sources->isEmpty()) {
            throw new HelpDeskMergeException('Selecione ao menos um chamado de origem diferente do destino.');
        }

        // Validações fora da transação (mensagens claras).
        if ($target->isMerged()) {
            throw new HelpDeskMergeException("O chamado de destino {$target->ticket_number} já está mesclado a outro — não pode receber mesclagem.");
        }
        if (!$this->isActive($target)) {
            throw new HelpDeskMergeException("O chamado de destino {$target->ticket_number} não está aberto — encerrados, cancelados ou resolvidos não entram na mesclagem.");
        }
        foreach ($sources as $s) {
            if ((int) $s->customer_id !== (int) $target->customer_id) {
                throw new HelpDeskMergeException("O chamado {$s->ticket_number} é de outro cliente — só é possível mesclar chamados do mesmo cliente.");
            }
            if ($s->isMerged()) {
                throw new HelpDeskMergeException("O chamado {$s->ticket_number} já está mesclado a outro chamado.");
            }
            if (!$this->isActive($s)) {
                throw new HelpDeskMergeException("O chamado {$s->ticket_number} não está aberto — só é possível mesclar chamados abertos (encerrados, cancelados ou resolvidos não entram).");
            }
        }

        // Teto de interações somadas (destino + origens).
        $total = HelpDeskTicketComment::whereIn('ticket_id', $sources->pluck('id')->push($target->id))->count();
        if ($total > $this->maxActions()) {
            throw new HelpDeskMergeException("A mesclagem excede o limite de {$this->maxActions()} interações somadas ({$total}).");
        }

        $opts = [
            'keep_requesters' => (bool) ($options['keep_requesters'] ?? false),
            'keep_cc'         => (bool) ($options['keep_cc'] ?? false),
            'keep_tags'       => (bool) ($options['keep_tags'] ?? false),
        ];

        $merged = DB::transaction(function () use ($target, $sources, $opts, $user) {
            // Lock (concorrência): recarrega destino + origens com FOR UPDATE e revalida o estado.
            $ids = $sources->pluck('id')->push($target->id)->all();
            HelpDeskTicket::whereIn('id', $ids)->lockForUpdate()->get(); // adquire os locks
            $target->refresh();
            if ($target->isMerged()) {
                throw new HelpDeskMergeException('O chamado de destino foi mesclado por outra operação — recarregue e tente de novo.');
            }

            $cc = collect((array) $target->cc_emails);
            $done = [];
            foreach ($sources as $src) {
                $src->refresh();
                if ($src->isMerged()) {
                    throw new HelpDeskMergeException("O chamado {$src->ticket_number} foi mesclado por outra operação — recarregue e tente de novo.");
                }

                // 1) Interações da origem → destino (preservando ONDE foram criadas em origin_ticket_id).
                HelpDeskTicketComment::where('ticket_id', $src->id)->whereNull('origin_ticket_id')
                    ->update(['origin_ticket_id' => $src->id]);
                HelpDeskTicketComment::where('ticket_id', $src->id)
                    ->update(['ticket_id' => $target->id]);

                // 1b) CORPO/DESCRIÇÃO da origem → vira uma INTERAÇÃO no destino, na DATA original
                // do chamado (entra na sequência cronológica da conversa). Sem isso, chamados cujo
                // conteúdo está só na descrição inicial (sem comentários) perdiam o conteúdo na mescla.
                // origin_ticket_id + channel='merge' permitem remover no desmesclar (idempotente).
                if (filled($src->description)) {
                    $desc = new HelpDeskTicketComment();
                    $desc->ticket_id         = $target->id;
                    $desc->origin_ticket_id  = $src->id;
                    $desc->author_user_id    = $src->requester_user_id;
                    $desc->author_contact_id = $src->customer_contact_id;
                    $desc->body              = "<p><em>— Conteúdo do chamado {$src->ticket_number} (mesclado) —</em></p>" . $src->description;
                    $desc->visibility        = 'customer';
                    $desc->is_system         = false;
                    $desc->channel           = 'merge';
                    $desc->no_charge         = true;
                    $desc->created_at        = $src->created_at;   // set direto = não é sobrescrito pelo Eloquent
                    $desc->updated_at        = $src->created_at;
                    $desc->save();
                }

                // 2) Opções (cada uma independente).
                if ($opts['keep_cc']) {
                    $cc = $cc->merge((array) $src->cc_emails);
                }
                if ($opts['keep_requesters'] && $src->requester_email) {
                    $cc->push($src->requester_email); // mantém o solicitante da origem em cópia
                }
                if ($opts['keep_tags'] && method_exists($src, 'tags')) {
                    $target->tags()->syncWithoutDetaching($src->tags()->pluck('helpdesk_tags.id')->all());
                }

                // 3) Marca a origem como mesclada (some das listagens) E a ENCERRA (status terminal
                //    'fechado') — fica evidente que saiu de circulação. O status original vai no log
                //    (meta.source_status_id) e é restaurado no unmerge.
                $srcStatusId = $src->status_id;
                $closedId = \App\Models\HelpDeskStatus::where('key', 'fechado')->value('id');
                $src->merged_into_id = $target->id;
                if ($closedId && (int) $src->status_id !== (int) $closedId) {
                    HelpDeskTicketEvent::log($src->id, 'status_changed', ['from_value' => $srcStatusId, 'to_value' => $closedId]);
                    $src->status_id = $closedId;
                }
                // 3b) Classificação do origem = a do DESTINO (chamado que fica ativo). O mesclado passa a
                //     refletir a triagem do destino. Guarda a original no log p/ restaurar no unmerge.
                $srcClassification = $src->only(['category_id', 'service_id', 'priority', 'level']);
                $src->category_id = $target->category_id;
                $src->service_id  = $target->service_id;
                $src->priority    = $target->priority;
                $src->level       = $target->level;
                $src->last_activity_at = now();
                $src->save();

                // 4) Log auditável + eventos nos dois chamados.
                HelpDeskTicketMerge::create([
                    'action' => 'merge', 'target_ticket_id' => $target->id, 'source_ticket_id' => $src->id,
                    'target_number' => $target->ticket_number, 'source_number' => $src->ticket_number,
                    'performed_by' => $user?->id, 'options' => $opts,
                    'meta' => ['source_status_id' => $srcStatusId, 'source_classification' => $srcClassification],
                ]);
                HelpDeskTicketEvent::log($target->id, 'merged', ['to_value' => $src->ticket_number, 'meta' => ['source_id' => $src->id, 'options' => $opts, 'by' => $user?->id]]);
                HelpDeskTicketEvent::log($src->id, 'merged_into', ['to_value' => $target->ticket_number, 'meta' => ['target_id' => $target->id, 'by' => $user?->id]]);
                $done[] = $src->ticket_number;
            }

            // cc únicos/limpos no destino.
            $target->cc_emails = $cc->filter()->map(fn ($e) => trim((string) $e))->filter()->unique()->values()->all();
            $target->last_activity_at = now();
            $target->save();

            return $target->fresh();
        });

        // Notifica a mesclagem (gatilho 'merged') — destino do merge (mesmo cliente; responsável do destino).
        \App\Services\HelpDeskTriggerEngine::dispatch('merged', $merged, [
            'actor_id' => $user?->id, 'actor_email' => $user?->email,
            'merged_sources' => $sources->pluck('ticket_number')->filter()->values()->all(),
        ]);

        return $merged;
    }

    /**
     * Desmescla um chamado de ORIGEM específico do chamado de DESTINO (desmescla parcial).
     * As interações criadas ORIGINALMENTE na origem voltam pra ela; as criadas no destino ficam.
     */
    public function unmerge(HelpDeskTicket $target, HelpDeskTicket $source, ?User $user): HelpDeskTicket
    {
        if ((int) $source->merged_into_id !== (int) $target->id) {
            throw new HelpDeskMergeException("O chamado {$source->ticket_number} não está mesclado no {$target->ticket_number}.");
        }

        return DB::transaction(function () use ($target, $source, $user) {
            HelpDeskTicket::whereIn('id', [$target->id, $source->id])->lockForUpdate()->get();
            $source->refresh();
            if ((int) $source->merged_into_id !== (int) $target->id) {
                throw new HelpDeskMergeException('Este chamado já foi desmesclado por outra operação.');
            }

            // 1) A descrição da origem foi INJETADA como interação (channel='merge') na mescla — a
            //    origem ainda tem a descrição no próprio campo, então aqui só REMOVE a cópia (evita
            //    duplicar no origem e acumular a cada re-merge). As demais interações voltam pra origem.
            HelpDeskTicketComment::where('ticket_id', $target->id)->where('origin_ticket_id', $source->id)
                ->where('channel', 'merge')->forceDelete();
            HelpDeskTicketComment::where('ticket_id', $target->id)->where('origin_ticket_id', $source->id)
                ->update(['ticket_id' => $source->id]);

            // 2) Status ATIVO de volta: restaura o status que a origem tinha na mesclagem (era ativo);
            //    fallback p/ "em_andamento" se aquele status não existir mais.
            $log = HelpDeskTicketMerge::where('action', 'merge')
                ->where('target_ticket_id', $target->id)->where('source_ticket_id', $source->id)
                ->latest('id')->first();
            $restoreStatusId = (int) data_get($log, 'meta.source_status_id');
            $status = $restoreStatusId ? \App\Models\HelpDeskStatus::find($restoreStatusId) : null;
            if (!$status || $status->is_terminal) {
                $status = \App\Models\HelpDeskStatus::where('key', 'em_andamento')->first();
            }

            $source->merged_into_id = null;
            if ($status) $source->status_id = $status->id;
            // Restaura a classificação original da origem (a mescla a tinha igualado à do destino).
            $cls = (array) data_get($log, 'meta.source_classification', []);
            foreach (['category_id', 'service_id', 'priority', 'level'] as $f) {
                if (array_key_exists($f, $cls)) $source->{$f} = $cls[$f];
            }
            $source->last_activity_at = now();
            $source->save();

            // 3) Log + eventos nos dois chamados.
            HelpDeskTicketMerge::create([
                'action' => 'unmerge', 'target_ticket_id' => $target->id, 'source_ticket_id' => $source->id,
                'target_number' => $target->ticket_number, 'source_number' => $source->ticket_number,
                'performed_by' => $user?->id, 'meta' => ['restored_status_id' => optional($status)->id],
            ]);
            HelpDeskTicketEvent::log($target->id, 'unmerged', ['to_value' => $source->ticket_number, 'meta' => ['source_id' => $source->id, 'by' => $user?->id]]);
            HelpDeskTicketEvent::log($source->id, 'unmerged_from', ['to_value' => $target->ticket_number, 'meta' => ['target_id' => $target->id, 'by' => $user?->id]]);

            return $source->fresh();
        });
    }
}
