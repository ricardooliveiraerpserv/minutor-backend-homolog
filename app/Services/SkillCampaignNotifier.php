<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\SkillSurvey;
use App\Models\SkillSurveyInvite;
use App\Models\User;
use App\Workflows\WorkflowConfigService;
use App\Workflows\WorkflowMailer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Campanhas de ATUALIZAÇÃO de competências — pop-up in-app + e-mail (workflow
 * `competencias.campanha`, configurável na Central) para os consultores, com
 * cobrança RECORRENTE de quem não preencheu (recurrence_days da Central + prazo
 * da campanha). Espelha o padrão do HireNotifier.
 *
 * Uma "campanha" é uma pesquisa INTERNA aberta, com PRAZO, que não seja a
 * auto-avaliação perene (token AUTOAVAL). A recorrência usa
 * skill_survey_invites.last_reminder_at/reminder_count (sem migration).
 */
class SkillCampaignNotifier
{
    public const WORKFLOW = 'competencias.campanha';

    public static function ctaUrl(SkillSurvey $survey): string
    {
        return '/competencias/responder/' . $survey->id;
    }

    /** URL ABSOLUTA do formulário de resposta (botão do e-mail). */
    private static function emailCtaUrl(SkillSurvey $survey): string
    {
        return rtrim((string) config('app.frontend_url', 'https://app.minutor.com.br'), '/') . self::ctaUrl($survey);
    }

    /** Disparo inicial: e-mail (workflow) + pop-up a todos os convidados pendentes. */
    public static function onLaunch(SkillSurvey $survey, ?User $actor = null): int
    {
        $mailer = app(WorkflowMailer::class);

        $invites = $survey->invites()->whereNotNull('user_id')
            ->where('status', '!=', SkillSurveyInvite::STATUS_SUBMITTED)->get();
        $userIds = $invites->pluck('user_id')->filter()->unique()->values()->all();

        $mails = 0;
        foreach (User::whereIn('id', $userIds)->where('enabled', true)->get() as $u) {
            try {
                if ($mailer->send(self::WORKFLOW, ['consultant' => $u, 'actor' => $actor], self::vars($survey), self::emailCtaUrl($survey), $survey->description, $survey->title)) {
                    $mails++;
                }
            } catch (\Throwable $e) {
                Log::warning('competencias.campanha: e-mail falhou', ['survey' => $survey->id, 'user' => $u->id, 'err' => $e->getMessage()]);
            }
        }

        self::popup($survey, $userIds, $actor?->id);

        // Baseline da recorrência: marca a data do 1º disparo em cada convite.
        if ($invites->isNotEmpty()) {
            SkillSurveyInvite::whereIn('id', $invites->pluck('id'))->update(['last_reminder_at' => now()]);
        }

        return $mails;
    }

    /** Lembrete MANUAL de um único convite (botão "Lembrar"). */
    public static function remindOne(SkillSurveyInvite $invite, ?User $actor = null): bool
    {
        $survey = $invite->survey;
        $user = $invite->user_id ? User::find($invite->user_id) : null;

        $sent = false;
        if ($user && $survey) {
            try {
                $sent = app(WorkflowMailer::class)->send(self::WORKFLOW, ['consultant' => $user, 'actor' => $actor], self::vars($survey), self::emailCtaUrl($survey), $survey->description, $survey->title);
            } catch (\Throwable $e) {
                Log::warning('competencias.campanha: lembrete falhou', ['invite' => $invite->id, 'err' => $e->getMessage()]);
            }
            self::popup($survey, [$user->id], $actor?->id);
        }

        $invite->forceFill([
            'reminder_count' => (int) $invite->reminder_count + 1,
            'last_reminder_at' => now(),
            'status' => $invite->status === SkillSurveyInvite::STATUS_PENDING ? SkillSurveyInvite::STATUS_SENT : $invite->status,
        ])->save();

        return $sent;
    }

    /**
     * Varredura diária: cobra quem NÃO preencheu, respeitando a recorrência da
     * Central (recurrence_days) e reforçando o pop-up. @return array{campaigns:int,pending:int,mails:int}
     */
    public static function sweep(?Carbon $today = null): array
    {
        $today = ($today ?: now())->startOfDay();
        $rd = (int) (app(WorkflowConfigService::class)->template(self::WORKFLOW)['recurrence_days'] ?? 0);
        $mailer = app(WorkflowMailer::class);

        $campaigns = SkillSurvey::where('type', SkillSurvey::TYPE_INTERNAL)
            ->where('status', SkillSurvey::STATUS_OPEN)
            ->where('public_token', '!=', SkillSurveyService::SELF_SURVEY_TOKEN)
            ->whereNotNull('deadline')
            ->get();

        $mails = 0;
        $pendingTotal = 0;
        foreach ($campaigns as $survey) {
            $pending = $survey->invites()->whereNotNull('user_id')
                ->where('status', '!=', SkillSurveyInvite::STATUS_SUBMITTED)->get();
            if ($pending->isEmpty()) {
                continue;
            }

            $pendingUserIds = [];
            foreach ($pending as $inv) {
                $user = User::find($inv->user_id);
                if (! $user || ! $user->enabled) {
                    continue;
                }
                $pendingUserIds[] = $user->id;

                // Reenvio de e-mail só se a Central definiu recorrência (>0) e já venceu o ciclo.
                if ($rd > 0 && self::due($inv->last_reminder_at ?? $inv->sent_at ?? $inv->created_at, $rd, $today)) {
                    try {
                        if ($mailer->send(self::WORKFLOW, ['consultant' => $user], self::vars($survey), self::emailCtaUrl($survey), $survey->description, $survey->title)) {
                            $mails++;
                        }
                    } catch (\Throwable $e) {
                        Log::warning('competencias.campanha: reenvio falhou', ['invite' => $inv->id, 'err' => $e->getMessage()]);
                    }
                    $inv->forceFill(['reminder_count' => (int) $inv->reminder_count + 1, 'last_reminder_at' => $today])->save();
                }
            }

            $pendingTotal += count($pendingUserIds);
            if ($pendingUserIds) {
                self::popup($survey, $pendingUserIds, null, $today);
            }
        }

        return ['campaigns' => $campaigns->count(), 'pending' => $pendingTotal, 'mails' => $mails];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Pop-up idempotente por dia + campanha (mesma cta_url), direcionado aos pendentes. */
    private static function popup(SkillSurvey $survey, array $userIds, ?int $createdBy, ?Carbon $day = null): void
    {
        $userIds = array_values(array_unique(array_filter($userIds)));
        if (empty($userIds)) {
            return;
        }
        $day = $day ?: now();
        $cta = self::ctaUrl($survey);
        $prazo = $survey->deadline ? ' Prazo: ' . $survey->deadline->format('d/m/Y') . '.' : '';
        $base = trim((string) ($survey->description ?: 'Revise e atualize suas competências.'));
        $msg = (mb_strlen($base) > 240 ? mb_substr($base, 0, 237) . '…' : $base) . $prazo;

        $payload = [
            'title'        => 'Atualize suas competências',
            'message'      => $msg,
            'type'         => 'action',
            'priority'     => 'high',
            'target_users' => $userIds,
            'cta_label'    => 'Atualizar competências',
            'cta_url'      => $cta,
            'visible'      => true,
            'send_email'   => false,
            'requires_ack' => false,
            'created_by'   => $createdBy,
            'resent_at'    => now(),
            'expires_at'   => optional($survey->deadline)->endOfDay() ?? now()->addDays(14),
        ];

        $existing = AppNotification::where('cta_url', $cta)
            ->whereDate('created_at', $day->toDateString())->first();
        if ($existing) {
            $existing->update($payload);
        } else {
            AppNotification::create($payload);
        }
    }

    private static function vars(SkillSurvey $survey): array
    {
        return [
            'titulo' => $survey->title,
            'prazo'  => $survey->deadline ? $survey->deadline->format('d/m/Y') : 'sem prazo',
        ];
    }

    /** Envio devido? (nunca enviado, ou último envio + N dias já passou). */
    private static function due($last, int $days, Carbon $today): bool
    {
        if (! $last) {
            return true;
        }
        try {
            $d = $last instanceof \DateTimeInterface ? Carbon::instance($last) : Carbon::parse((string) $last);
            return $d->startOfDay()->addDays($days)->lte($today);
        } catch (\Throwable) {
            return true;
        }
    }
}
