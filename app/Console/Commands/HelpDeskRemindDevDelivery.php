<?php

namespace App\Console\Commands;

use App\Models\AppNotification;
use App\Models\HelpDeskTicket;
use App\Models\User;
use App\Services\BusinessCalendarService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Lembretes de PRAZO DE ENTREGA em homologação dos chamados "Em Desenvolvimento".
 *
 * Regras:
 *  - 3, 2 e 1 dia útil ANTES da data de entrega (dev_delivery_at): pop-up (1x por marco)
 *    para o CONSULTOR (assignee) + COORDENADOR(ES) DE SUSTENTAÇÃO. No marco de 1 dia o texto
 *    diz "amanhã expira o prazo".
 *  - No dia do vencimento e DEPOIS de vencido: avisa o CONSULTOR DIARIAMENTE.
 *  - A notificação aparece no "Meu Dia" (Central) e como pop-up; CTA abre o chamado.
 *
 * Idempotente por dia: 1 notificação por chamado por dia (title inclui o nº do chamado;
 * dedup por título + data). Marcos caem em dias distintos → cada marco gera um pop-up novo.
 *
 * Disparo em homolog/prod: Render Cron Job → `php artisan help-desk:remind-dev-delivery`.
 */
class HelpDeskRemindDevDelivery extends Command
{
    protected $signature = 'help-desk:remind-dev-delivery {--date= : Data de referência YYYY-MM-DD (p/ teste)}';
    protected $description = 'Lembretes de prazo de entrega em homologação (chamados Em Desenvolvimento)';

    public function handle(BusinessCalendarService $cal): int
    {
        $tz    = 'America/Sao_Paulo';
        $today = $this->option('date')
            ? Carbon::parse($this->option('date'), $tz)->startOfDay()
            : Carbon::now($tz)->startOfDay();
        $isBiz = $cal->isBusinessDay($today);

        // Destinatários fixos: coordenador(es) de sustentação.
        $coordIds = User::query()
            ->where('type', 'coordenador')
            ->where('coordinator_type', 'sustentacao')
            ->pluck('id')->map(fn ($i) => (int) $i)->all();

        $tickets = HelpDeskTicket::query()
            ->whereHas('status', fn ($q) => $q->where('key', 'em_desenvolvimento'))
            ->whereNotNull('dev_delivery_at')
            ->get();

        $n = 0;
        foreach ($tickets as $t) {
            $due         = Carbon::parse((string) $t->dev_delivery_at, $tz)->startOfDay();
            $consultorId = $t->assignee_id ? (int) $t->assignee_id : null;
            $num         = $t->ticket_number ?: ('#' . $t->id);
            $subj        = \Illuminate\Support\Str::limit((string) $t->subject, 60);
            $dueBr       = $due->format('d/m/Y');

            if ($due->gt($today)) {
                // Antes do vencimento: só avaliamos marcos em DIA ÚTIL (a contagem de dias úteis
                // só faz sentido rodando num dia útil).
                if (!$isBiz) {
                    continue;
                }
                // Dias úteis restantes ATÉ a entrega (exclui o dia de hoje). businessDaysBetween é inclusivo.
                $remaining = $cal->businessDaysBetween($today, $due) - 1;
                if (!in_array($remaining, [1, 2, 3], true)) {
                    continue;
                }
                $targets = array_values(array_unique(array_filter(array_merge([$consultorId], $coordIds))));
                if (empty($targets)) {
                    continue;
                }
                if ($remaining === 1) {
                    $msg  = "Chamado {$num} — {$subj}: AMANHÃ expira o prazo de entrega em homologação (previsto para {$dueBr}).";
                    $prio = 'high';
                } else {
                    $msg  = "Chamado {$num} — {$subj}: entrega em homologação prevista para {$dueBr}. Faltam {$remaining} dias úteis.";
                    $prio = $remaining === 2 ? 'high' : 'medium';
                }
                $this->upsert($t, $num, $targets, $msg, $prio, $due->copy()->endOfDay(), $today);
                $n++;
            } else {
                // Vence hoje ou já venceu: avisa o CONSULTOR diariamente.
                if (!$consultorId) {
                    continue;
                }
                $msg = $due->isSameDay($today)
                    ? "Chamado {$num} — {$subj}: o prazo de entrega em homologação vence HOJE ({$dueBr})."
                    : "Chamado {$num} — {$subj}: o prazo de entrega em homologação EXPIROU (venceu em {$dueBr}). Atualize a previsão ou conclua a entrega.";
                $this->upsert($t, $num, [$consultorId], $msg, 'critical', $today->copy()->endOfDay(), $today);
                $n++;
            }
        }

        $this->info("lembretes de entrega em homologação: {$n} chamado(s) notificado(s)");
        return self::SUCCESS;
    }

    /** Cria/atualiza 1 notificação por chamado por dia (title inclui o nº do chamado). */
    private function upsert(HelpDeskTicket $t, string $num, array $targets, string $msg, string $prio, Carbon $expiresAt, Carbon $today): void
    {
        $title = "Entrega em homologação · {$num}";
        $cta   = '/help-desk/tickets/' . $t->id;

        $existing = AppNotification::where('title', $title)
            ->whereDate('created_at', $today->toDateString())
            ->first();

        if ($existing) {
            $existing->update([
                'message'      => $msg,
                'priority'     => $prio,
                'target_users' => $targets,
                'visible'      => true,
                'resent_at'    => now(),
                'expires_at'   => $expiresAt,
            ]);
            return;
        }

        AppNotification::create([
            'title'        => $title,
            'message'      => $msg,
            'type'         => 'action',
            'priority'     => $prio,
            'target_users' => $targets,
            'cta_label'    => 'Abrir chamado',
            'cta_url'      => $cta,
            'visible'      => true,
            'send_email'   => false,
            'requires_ack' => false,
            'created_by'   => (int) ($t->assignee_id ?: ($targets[0] ?? 1)),
            'resent_at'    => now(),
            'expires_at'   => $expiresAt,
        ]);
    }
}
