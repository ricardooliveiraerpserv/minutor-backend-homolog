<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\SkillHireCard;
use App\Workflows\WorkflowMailer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Avisos de "Nova contratação" para o ADMINISTRATIVO.
 *
 * - onCreated(): ao cadastrar → e-mail (Central de Workflows `hire.new`) + pop-up in-app imediato.
 * - sweep(): rodado 1x/dia pelo command `contratacao:notify-administrativo` → mantém a ação
 *   fixada a partir da DATA DE PRIMEIRO CONTATO e vira ATRASO se passar da data, enquanto o card
 *   não estiver em Finalizado/Pausados.
 *
 * A aba "Ações" do Meu Dia (ApprovalController::homeActions) usa a mesma regra de pendência
 * (contagem ao vivo). Aqui é o canal de PUSH (pop-up + e-mail).
 */
class HireNotifier
{
    /** Buckets em que a contratação ainda está PENDENTE (cobra/atrasa). */
    public const PENDING_BUCKETS = ['aguardando_assinatura', 'em_andamento'];

    public const CTA_URL = '/competencias/contratacao';

    /** Ao incluir uma contratação: dispara e-mail + pop-up imediato ao administrativo. */
    public static function onCreated(SkillHireCard $card): void
    {
        // E-mail via Central de Workflows (destinatários/recorrência configuráveis lá).
        try {
            app(WorkflowMailer::class)->send('hire.new', ['actor' => $card->createdUser], self::mailVars($card));
        } catch (\Throwable $e) {
            Log::warning('hire.new: e-mail falhou', ['card' => $card->id, 'err' => $e->getMessage()]);
        }

        // Pop-up in-app imediato (o administrativo vê na hora que foi cadastrada).
        try {
            $pc = self::firstContactDate($card);
            $quando = $pc ? ' Primeiro contato: ' . $pc->format('d/m/Y') . '.' : '';
            AppNotification::create([
                'title'        => 'Nova contratação: ' . $card->title,
                'message'      => 'Nova contratação incluída — providenciar passagem/onboarding.' . $quando,
                'type'         => 'action',
                'priority'     => 'high',
                'target_roles' => ['administrativo'],
                'cta_label'    => 'Abrir contratações',
                'cta_url'      => self::CTA_URL,
                'visible'      => true,
                'send_email'   => false,   // o e-mail já sai pelo workflow acima
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
     * Varredura diária: cria/atualiza 1 pop-up agregado p/ o administrativo com as contratações
     * cuja data de primeiro contato já chegou e que ainda estão pendentes; destaca as atrasadas.
     *
     * @return array{pending:int,overdue:int}
     */
    public static function sweep(?Carbon $today = null): array
    {
        $today = ($today ?: now())->startOfDay();

        $due = self::pendingWithFirstContactDue($today)->get();
        $pending = $due->count();
        $overdue = $due->filter(fn ($c) => optional(self::firstContactDate($c))->lt($today))->count();

        if ($pending === 0) {
            return ['pending' => 0, 'overdue' => 0];
        }

        $msg = $overdue > 0
            ? "Você tem {$pending} contratação(ões) a providenciar, sendo {$overdue} EM ATRASO. Abra e conclua o quanto antes."
            : "Você tem {$pending} contratação(ões) a providenciar hoje.";
        $title = $overdue > 0 ? 'Contratações em atraso' : 'Contratações a providenciar';

        // 1 notificação por dia p/ o grupo administrativo (idempotente).
        $existing = AppNotification::whereJsonContains('target_roles', 'administrativo')
            ->where('cta_url', self::CTA_URL)
            ->whereDate('created_at', $today->toDateString())
            ->first();

        $payload = [
            'title'    => $title,
            'message'  => $msg,
            'priority' => $overdue > 0 ? 'critical' : 'high',
            'resent_at' => now(),
            'expires_at' => $today->copy()->endOfDay(),
        ];

        if ($existing) {
            $existing->update($payload);
        } else {
            AppNotification::create(array_merge($payload, [
                'type'         => 'action',
                'target_roles' => ['administrativo'],
                'cta_label'    => 'Abrir contratações',
                'cta_url'      => self::CTA_URL,
                'visible'      => true,
                'send_email'   => false,
                'requires_ack' => false,
            ]));
        }

        return ['pending' => $pending, 'overdue' => $overdue];
    }

    /** Cards pendentes cuja data de primeiro contato já chegou (<= hoje). */
    public static function pendingWithFirstContactDue(Carbon $today)
    {
        return SkillHireCard::whereIn('bucket', self::PENDING_BUCKETS)
            ->whereRaw("NULLIF(form->>'data_primeiro_contato', '') IS NOT NULL")
            ->whereRaw("(form->>'data_primeiro_contato')::date <= ?", [$today->toDateString()]);
    }

    private static function firstContactDate(SkillHireCard $card): ?Carbon
    {
        $v = is_array($card->form) ? ($card->form['data_primeiro_contato'] ?? '') : '';
        if (!$v) return null;
        try { return Carbon::parse($v)->startOfDay(); } catch (\Throwable) { return null; }
    }

    private static function mailVars(SkillHireCard $card): array
    {
        $form = is_array($card->form) ? $card->form : [];
        $fmt = fn ($d) => $d ? (Carbon::hasFormat($d, 'Y-m-d') ? Carbon::parse($d)->format('d/m/Y') : $d) : '';
        return [
            'nome'            => $card->title,
            'cargo'           => $card->cargo ?: '—',
            'modalidade'      => SkillHireCard::MODALIDADES[$card->modalidade] ?? '—',
            'contato'         => (string) ($form['contato'] ?? ''),
            'primeiro_contato' => $fmt($form['data_primeiro_contato'] ?? ''),
            'inicio'          => $fmt($form['start_date'] ?? ''),
        ];
    }
}
