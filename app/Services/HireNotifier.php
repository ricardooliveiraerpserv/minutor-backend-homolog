<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\SkillHireCard;
use App\Workflows\WorkflowConfigService;
use App\Workflows\WorkflowMailer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Avisos de contratação para o ADMINISTRATIVO — DOIS workflows:
 *
 *  - `hire.new` (Pendente de contratação): enviado na inclusão e reenviado a cada
 *    N dias (recurrence_days) enquanto o card NÃO estiver em Finalizado/Pausados.
 *  - `hire.first_contact` (Pendente de primeiro contato): a partir da data de primeiro
 *    contato, reenviado a cada N dias enquanto o card seguir em "Aguardando contrato".
 *    PARA quando for movido para "Em andamento".
 *
 * A recorrência real é dirigida pelo `recurrence_days` de cada workflow (Central),
 * com a última data de envio guardada no próprio `form` do card (`_hire_new_at`,
 * `_first_contact_at`) — sem migration.
 *
 * onCreated(): e-mail hire.new imediato + pop-up in-app. sweep(): rodado 1x/dia.
 */
class HireNotifier
{
    /** Buckets em que a contratação ainda está PENDENTE (cobra/atrasa). */
    public const PENDING_BUCKETS = ['aguardando_assinatura', 'em_andamento'];

    public const CTA_URL = '/competencias/contratacao';

    /** Ao incluir uma contratação/parceiro: e-mail (hire.new / partner.new) + pop-up imediato ao administrativo. */
    public static function onCreated(SkillHireCard $card): void
    {
        $isPartner = (is_array($card->form) ? ($card->form['kind'] ?? 'person') : 'person') === 'partner';
        $workflow  = $isPartner ? 'partner.new' : 'hire.new';

        try {
            if (app(WorkflowMailer::class)->send($workflow, ['actor' => $card->createdUser], self::mailVars($card))) {
                $card->update(['form' => array_merge(is_array($card->form) ? $card->form : [], ['_hire_new_at' => now()->toDateString()])]);
            }
        } catch (\Throwable $e) {
            Log::warning($workflow . ': e-mail falhou', ['card' => $card->id, 'err' => $e->getMessage()]);
        }

        try {
            $pc = self::firstContactDate($card);
            $quando = $pc ? ' Primeiro contato: ' . $pc->format('d/m/Y') . '.' : '';
            AppNotification::create([
                'title'        => ($isPartner ? 'Novo parceiro: ' : 'Nova contratação: ') . $card->title,
                'message'      => $isPartner
                    ? 'Novo parceiro incluído — providenciar assinatura do contrato e documentação.'
                    : 'Nova contratação incluída — providenciar passagem/onboarding.' . $quando,
                'type'         => 'action',
                'priority'     => 'high',
                'target_roles' => ['administrativo'],
                'cta_label'    => 'Abrir contratações',
                'cta_url'      => self::CTA_URL,
                'visible'      => true,
                'send_email'   => false,
                'requires_ack' => false,
                'created_by'   => $card->created_by,
                'resent_at'    => now(),
                'expires_at'   => now()->endOfDay(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('hire.new: pop-up falhou', ['card' => $card->id, 'err' => $e->getMessage()]);
        }
    }

    /**
     * Varredura diária: reenvia os dois e-mails conforme recorrência e mantém o
     * pop-up/Meu Dia do administrativo. @return array{pending:int,overdue:int,mails:int}
     */
    public static function sweep(?Carbon $today = null): array
    {
        $today  = ($today ?: now())->startOfDay();
        $cfg    = app(WorkflowConfigService::class);
        $mailer = app(WorkflowMailer::class);
        $rdNew  = (int) ($cfg->template('hire.new')['recurrence_days'] ?? 0);
        $rdFc   = (int) ($cfg->template('hire.first_contact')['recurrence_days'] ?? 0);

        $mails = 0;
        foreach (SkillHireCard::whereIn('bucket', self::PENDING_BUCKETS)->get() as $card) {
            $form = is_array($card->form) ? $card->form : [];
            $changed = false;

            // hire.new — reenvia a cada rdNew dias enquanto pendente (qualquer PENDING_BUCKET).
            if ($rdNew > 0 && self::due($form['_hire_new_at'] ?? null, $rdNew, $today)) {
                try {
                    if ($mailer->send('hire.new', ['actor' => $card->createdUser], self::mailVars($card))) {
                        $form['_hire_new_at'] = $today->toDateString(); $changed = true; $mails++;
                    }
                } catch (\Throwable $e) { Log::warning('hire.new recorrente falhou', ['card' => $card->id, 'err' => $e->getMessage()]); }
            }

            // hire.first_contact — SÓ enquanto "aguardando_assinatura" e a data já chegou.
            // (para quando movido para "em_andamento"). rdFc=0 → envia 1x quando a data chega.
            $fc = self::firstContactDate($card);
            if ($card->bucket === 'aguardando_assinatura' && $fc && $fc->lte($today)) {
                $last = $form['_first_contact_at'] ?? null;
                if (! $last || ($rdFc > 0 && self::due($last, $rdFc, $today))) {
                    try {
                        if ($mailer->send('hire.first_contact', ['actor' => $card->createdUser], self::mailVars($card))) {
                            $form['_first_contact_at'] = $today->toDateString(); $changed = true; $mails++;
                        }
                    } catch (\Throwable $e) { Log::warning('hire.first_contact falhou', ['card' => $card->id, 'err' => $e->getMessage()]); }
                }
            }

            if ($changed) $card->update(['form' => $form]);
        }

        // Pop-up/Meu Dia agregado (contratações cuja data de primeiro contato já chegou).
        $due = self::pendingWithFirstContactDue($today)->get();
        $pending = $due->count();
        $overdue = $due->filter(fn ($c) => optional(self::firstContactDate($c))->lt($today))->count();

        if ($pending > 0) {
            $msg = $overdue > 0
                ? "Você tem {$pending} contratação(ões) a providenciar, sendo {$overdue} EM ATRASO. Abra e conclua o quanto antes."
                : "Você tem {$pending} contratação(ões) a providenciar hoje.";
            $title = $overdue > 0 ? 'Contratações em atraso' : 'Contratações a providenciar';
            $payload = ['title' => $title, 'message' => $msg, 'priority' => $overdue > 0 ? 'critical' : 'high',
                'resent_at' => now(), 'expires_at' => $today->copy()->endOfDay()];

            $existing = AppNotification::whereJsonContains('target_roles', 'administrativo')
                ->where('cta_url', self::CTA_URL)->whereDate('created_at', $today->toDateString())->first();
            if ($existing) {
                $existing->update($payload);
            } else {
                AppNotification::create(array_merge($payload, ['type' => 'action', 'target_roles' => ['administrativo'],
                    'cta_label' => 'Abrir contratações', 'cta_url' => self::CTA_URL, 'visible' => true,
                    'send_email' => false, 'requires_ack' => false]));
            }
        }

        return ['pending' => $pending, 'overdue' => $overdue, 'mails' => $mails];
    }

    /** Cards pendentes cuja data de primeiro contato já chegou (<= hoje). */
    public static function pendingWithFirstContactDue(Carbon $today)
    {
        return SkillHireCard::whereIn('bucket', self::PENDING_BUCKETS)
            ->whereRaw("NULLIF(form->>'data_primeiro_contato', '') IS NOT NULL")
            ->whereRaw("(form->>'data_primeiro_contato')::date <= ?", [$today->toDateString()]);
    }

    /** Envio devido? (nunca enviado, ou último envio + N dias já passou). */
    private static function due(?string $last, int $days, Carbon $today): bool
    {
        if (! $last) return true;
        try { return Carbon::parse($last)->startOfDay()->addDays($days)->lte($today); }
        catch (\Throwable) { return true; }
    }

    private static function firstContactDate(SkillHireCard $card): ?Carbon
    {
        $v = is_array($card->form) ? ($card->form['data_primeiro_contato'] ?? '') : '';
        if (! $v) return null;
        try { return Carbon::parse($v)->startOfDay(); } catch (\Throwable) { return null; }
    }

    private static function mailVars(SkillHireCard $card): array
    {
        $form = is_array($card->form) ? $card->form : [];
        $fmt = fn ($d) => $d ? (Carbon::hasFormat($d, 'Y-m-d') ? Carbon::parse($d)->format('d/m/Y') : $d) : '';
        return [
            'nome'             => $card->title,
            'cargo'            => $card->cargo ?: '—',
            'modalidade'       => SkillHireCard::MODALIDADES[$card->modalidade] ?? '—',
            'contato'          => (string) ($form['contato'] ?? ''),
            'primeiro_contato' => $fmt($form['data_primeiro_contato'] ?? ''),
            'inicio'           => $fmt($form['start_date'] ?? ''),
        ];
    }

    /**
     * MOVIMENTAÇÃO da contratação (mudança de situação/bucket) → avisa o SOLICITANTE
     * (quem criou o card). E-mail via workflow `hire.movement` (audiência `autor` =
     * $card->createdUser, configurável na Central) + pop-up in-app direto ao solicitante.
     * Não faz nada se não houve mudança real ou se o card não tem solicitante.
     */
    public static function onMoved(SkillHireCard $card, ?string $from, string $to, ?string $movedBy = null): void
    {
        // BLINDADO: uma falha aqui NUNCA pode derrromper a movimentação (move/complete).
        try {
            // Solicitante = quem CRIOU o card (created_by). NÃO é created_user_id (esse é o
            // usuário do CONTRATADO, gravado só na conclusão).
            $solicitante = $card->created_by ? \App\Models\User::find($card->created_by) : null;
            if ($from === $to || ! $solicitante) {
                return;
            }
            $labels  = SkillHireCard::BUCKETS;
            $deLbl   = $labels[$from] ?? ($from ?: '—');
            $paraLbl = $labels[$to] ?? $to;
            $vars = array_merge(self::mailVars($card), [
                'de'   => $deLbl,
                'para' => $paraLbl,
                'por'  => $movedBy ?: '—',
                'data' => now()->format('d/m/Y H:i'),
            ]);

            try {
                app(WorkflowMailer::class)->send('hire.movement', ['actor' => $solicitante], $vars);
            } catch (\Throwable $e) {
                Log::warning('hire.movement: e-mail falhou', ['card' => $card->id, 'err' => $e->getMessage()]);
            }

            AppNotification::create([
                'title'        => 'Contratação ' . $card->title . ': ' . $paraLbl,
                'message'      => 'A contratação de ' . $card->title . ' foi movida de "' . $deLbl . '" para "' . $paraLbl . '"' . ($movedBy ? ' por ' . $movedBy : '') . '.',
                'type'         => 'info',
                'priority'     => 'normal',
                'target_users' => [$card->created_by],
                'cta_label'    => 'Abrir contratações',
                'cta_url'      => self::CTA_URL,
                'visible'      => true,
                'send_email'   => false,
                'requires_ack' => false,
                'created_by'   => $card->created_by,
                'resent_at'    => now(),
                'expires_at'   => now()->addDays(7),
            ]);
        } catch (\Throwable $e) {
            Log::warning('hire.movement: falhou', ['card' => $card->id ?? null, 'err' => $e->getMessage()]);
        }
    }
}
