<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Regra de recorrência de lembrete de uma ação não resolvida.
 * O catálogo DEFAULTS define os tipos de ação lembráveis + como o aviso aparece.
 */
class ActionReminderRule extends Model
{
    protected $fillable = ['key', 'enabled', 'unit', 'interval', 'last_fired_at', 'notification_id'];

    protected $casts = [
        'enabled'       => 'boolean',
        'interval'      => 'integer',
        'last_fired_at' => 'datetime',
    ];

    /** Tipos de ação que podem ter lembrete recorrente + textos/links do aviso. */
    public const DEFAULTS = [
        'approve_ts' => [
            'label'     => 'Apontamentos para aprovar (projetos)',
            'audience'  => 'Coordenadores de projeto',
            'priority'  => 'high',
            'title'     => 'Apontamentos aguardando sua aprovação',
            'cta_label' => 'Revisar apontamentos',
            'cta_url'   => '/approvals?tab=timesheets',
            'workflow'  => 'timesheet.pending_approval',
        ],
        // Sustentação: regra própria — só lembra dos apontamentos do DIA ANTERIOR.
        'approve_ts_sust' => [
            'label'     => 'Apontamentos para aprovar (sustentação)',
            'audience'  => 'Coordenadores de sustentação · só o dia anterior',
            'priority'  => 'high',
            'title'     => 'Apontamentos de sustentação do dia anterior para aprovar',
            'cta_label' => 'Revisar apontamentos',
            'cta_url'   => '/approvals?tab=timesheets&scope=sustentacao',
            'workflow'  => 'timesheet.pending_approval.sustentacao',
        ],
        'approve_exp' => [
            'label'     => 'Despesas para aprovar',
            'audience'  => 'Coordenadores de projeto',
            'priority'  => 'high',
            'title'     => 'Despesas aguardando aprovação',
            'cta_label' => 'Revisar despesas',
            'cta_url'   => '/approvals?tab=expenses',
            'workflow'  => 'expense.pending_approval',
        ],
        'pay_exp' => [
            'label'     => 'Despesas para pagar',
            'audience'  => 'Administrativo',
            'priority'  => 'medium',
            'title'     => 'Despesas aprovadas aguardando pagamento',
            'cta_label' => 'Pagar despesas',
            'cta_url'   => '/pagamento-despesas',
            'workflow'  => 'expense.approved_pending_payment',
        ],
        'fix_ts_adjust' => [
            'label'     => 'Apontamentos para ajustar',
            'audience'  => 'Consultores',
            'priority'  => 'high',
            'title'     => 'Você tem apontamentos com ajuste solicitado',
            'cta_label' => 'Ver apontamentos',
            'cta_url'   => '/timesheets?status=adjustment_requested',
            'workflow'  => 'timesheet.adjustment',
        ],
        'fix_ts_rejected' => [
            'label'     => 'Apontamentos rejeitados',
            'audience'  => 'Consultores',
            'priority'  => 'critical',
            'title'     => 'Você tem apontamentos rejeitados',
            'cta_label' => 'Ver apontamentos',
            'cta_url'   => '/timesheets?status=rejected',
            'workflow'  => 'timesheet.rejected',
        ],
        'fix_exp' => [
            'label'     => 'Despesas para ajustar',
            'audience'  => 'Consultores',
            'priority'  => 'high',
            'title'     => 'Você tem despesas com ajuste solicitado',
            'cta_label' => 'Ver despesas',
            'cta_url'   => '/expenses',
            'workflow'  => 'expense.adjustment',
        ],
        'fix_exp_rejected' => [
            'label'     => 'Despesas rejeitadas',
            'audience'  => 'Consultores',
            'priority'  => 'critical',
            'title'     => 'Você tem despesas rejeitadas',
            'cta_label' => 'Ver despesas',
            'cta_url'   => '/expenses',
            'workflow'  => 'expense.rejected',
        ],
    ];

    /** Está na hora de disparar este lembrete? (mesma lógica de horas/dias das notificações.) */
    public function isDue(\Illuminate\Support\Carbon $now): bool
    {
        if (!$this->enabled) return false;
        $v = max(1, (int) $this->interval);
        if (!$this->last_fired_at) return true;

        return $this->unit === 'hours'
            ? $this->last_fired_at->copy()->addHours($v)->lte($now)
            : $this->last_fired_at->copy()->startOfDay()->addDays($v)->lte($now->copy()->startOfDay());
    }
}
