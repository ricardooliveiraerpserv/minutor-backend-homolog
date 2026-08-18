<?php

namespace App\Services;

use App\Models\ContractHoursAlert;
use App\Models\Project;
use App\Models\SystemSetting;
use App\Models\Timesheet;
use App\Workflows\WorkflowMailer;
use App\Workflows\WorkflowRecipientResolver;
use Illuminate\Support\Facades\Log;

/**
 * Alertas automáticos de consumo de horas por contrato.
 *
 * Regra de consumo = a MESMA da Gestão de Contratos (Project::managementBreakdown):
 * horas aprovadas + pendentes, apuradas pelo tipo do contrato (BH Fixo/Fechado =
 * contrato integral; BH Mensal = acumulado mês a mês). O percentual dispara faixas
 * de 70/80/90/100% e, a partir daí, a cada 10% (110, 120, ...). Quando o consumo
 * salta várias faixas de uma vez, só a MAIOR é enviada.
 *
 * Nada aqui bloqueia a aprovação de apontamento: o disparo roda em 2º plano (Job) e
 * qualquer falha/ausência de destinatário só é registrada no histórico para correção.
 */
class ContractHoursConsumptionAlertService
{
    /** Liga/desliga mestre — nasce DESLIGADO. */
    public const FLAG_KEY = 'contract_hours_consumption_alerts_enabled';

    /** Workflow (Central de Workflows) que resolve destinatários + modelo do e-mail. */
    public const WORKFLOW_KEY = 'contract.hours_alert';

    public function __construct(
        private WorkflowMailer $mailer,
        private WorkflowRecipientResolver $resolver,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) SystemSetting::get(self::FLAG_KEY, false);
    }

    public function setEnabled(bool $enabled): void
    {
        SystemSetting::set(
            self::FLAG_KEY,
            $enabled,
            'boolean',
            'contracts',
            'Liga/desliga o envio automático de alertas de consumo de horas dos contratos.'
        );
    }

    /**
     * Avalia um projeto após uma aprovação e, se cruzou uma nova faixa, envia o alerta.
     * Idempotente: a mesma faixa/limite não é reenviada.
     */
    public function evaluateProject(int $projectId): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $project = $this->loadProject($projectId);
        if (!$project || !$this->isAlertable($project)) {
            return;
        }

        $m = $this->metrics($project);
        if ($m['available'] <= 0) {
            return;
        }

        $band = $this->targetBand($m['percentual']);
        if ($band === null) {
            return; // abaixo de 70% — nada a alertar
        }

        $availSnap = (int) round($m['available']);

        $existing = ContractHoursAlert::where('project_id', $project->id)
            ->where('band', $band)
            ->where('available_snapshot', $availSnap)
            ->first();

        // Já enviada com sucesso para esta faixa/limite → nada a fazer.
        if ($existing && $existing->status === 'sent') {
            return;
        }

        $this->deliver($project, $m, $band, $availSnap, $existing);
    }

    /**
     * Reenvio manual (Gestão de Contratos) de um alerta que falhou / ficou sem
     * destinatário. Recalcula os números atuais e reenvia a mesma faixa.
     * Roda mesmo com a flag mestre desligada — é uma ação explícita do admin.
     */
    public function resend(ContractHoursAlert $alert): ContractHoursAlert
    {
        $project = $this->loadProject((int) $alert->project_id);
        if (!$project) {
            $alert->update(['status' => 'failed', 'error' => 'Projeto não encontrado.']);
            return $alert->fresh();
        }

        $m = $this->metrics($project);
        $this->deliver($project, $m, (int) $alert->band, (int) $alert->available_snapshot, $alert);

        return $alert->fresh();
    }

    // ───────────────────────── núcleo ─────────────────────────

    /** Resolve destinatários, envia e grava/atualiza o histórico. */
    private function deliver(Project $project, array $m, int $band, int $availSnap, ?ContractHoursAlert $existing): void
    {
        $ctx  = $this->context($project);
        $vars = $this->vars($project, $m, $band);

        $rcpt   = $this->resolver->resolve(self::WORKFLOW_KEY, $ctx);
        $status = 'no_recipient';
        $error  = 'Nenhum destinatário configurado (contato marcado para receber alerta de consumo ou executivo de contas).';
        $sentAt = null;

        if (!empty($rcpt['to'])) {
            $ok = false;
            try {
                $ok = $this->mailer->send(self::WORKFLOW_KEY, $ctx, $vars);
            } catch (\Throwable $e) {
                Log::warning('[hours_alert] falha no envio', ['project' => $project->id, 'err' => $e->getMessage()]);
                $error = 'Falha no envio do e-mail: ' . $e->getMessage();
            }
            if ($ok) {
                $status = 'sent';
                $error  = null;
                $sentAt = now();
            } elseif ($status !== 'no_recipient' && !$error) {
                $status = 'failed';
                $error  = 'Falha no envio do e-mail.';
            } else {
                $status = 'failed';
                $error  = $error ?: 'Falha no envio do e-mail.';
            }
        }

        $payload = [
            'project_id'         => $project->id,
            'contract_id'        => $project->contract->id ?? null,
            'band'               => $band,
            'available_snapshot' => $availSnap,
            'percentual'         => round($m['percentual'], 2),
            'available'          => round($m['available'], 2),
            'consumed'           => round($m['consumed'], 2),
            'approved'           => round($m['approved'], 2),
            'balance'            => round($m['balance'], 2),
            'basis'              => $m['basis'],
            'classification'     => $this->classification($band),
            'recipients_to'      => $rcpt['to'] ?? [],
            'recipients_cc'      => $rcpt['cc'] ?? [],
            'status'             => $status,
            'error'              => $error,
            'sent_at'            => $sentAt,
        ];

        if ($existing) {
            $existing->update($payload);
        } else {
            ContractHoursAlert::create($payload);
        }
    }

    /** Métricas do contrato: mesma fonte da Gestão de Contratos. */
    public function metrics(Project $project): array
    {
        $b = $project->managementBreakdown();
        $available = (float) ($b['available'] ?? 0);
        $consumed  = (float) ($b['consumed'] ?? 0);
        $balance   = (float) ($b['balance'] ?? 0);
        $pct = $available > 0 ? round(($consumed / $available) * 100, 2) : 0.0;

        return [
            'available'   => $available,
            'consumed'    => $consumed,
            'balance'     => $balance,
            'percentual'  => $pct,
            'approved'    => $this->approvedOnlyHours($project),
            'basis'       => $this->basis($project),
        ];
    }

    /** Só contratos com limite de horas (BH Fixo, BH Mensal, Fechado). */
    private function isAlertable(Project $project): bool
    {
        return $project->isBankHoursMonthly()
            || $project->isBankHoursFixed()
            || $project->isClosedContract();
    }

    private function basis(Project $project): string
    {
        if ($project->isBankHoursMonthly()) return 'monthly';
        if ($project->isClosedContract())   return 'closed';
        return 'fixed';
    }

    /** Escada de faixas: 70/80/90/100 e, daí em diante, a cada 10%. Retorna a MAIOR atingida. */
    public function targetBand(float $pct): ?int
    {
        if ($pct < 70)  return null;
        if ($pct < 80)  return 70;
        if ($pct < 90)  return 80;
        if ($pct < 100) return 90;
        return (int) (floor($pct / 10) * 10); // 100, 110, 120, ...
    }

    private function classification(int $band): string
    {
        if ($band < 100)  return 'Atenção';
        if ($band === 100) return 'Limite atingido';
        return 'Excedido';
    }

    /** Soma das horas SÓ aprovadas (informativo no e-mail), incluindo subprojetos. */
    private function approvedOnlyHours(Project $project): float
    {
        $ids = [$project->id];
        if ($project->relationLoaded('childProjects') && $project->childProjects) {
            foreach ($project->childProjects as $c) {
                $ids[] = $c->id;
            }
        }
        $min = Timesheet::whereIn('project_id', $ids)
            ->where('status', 'approved')
            ->sum('effort_minutes');
        return round(((float) $min) / 60, 2);
    }

    private function context(Project $project): array
    {
        return [
            'contract' => $project->contract,
            'project'  => $project,
            'customer' => $project->customer,
        ];
    }

    private function vars(Project $project, array $m, int $band): array
    {
        $saldo     = $m['balance'] >= 0 ? $this->h($m['balance']) : '0,0 h';
        $excedente = $m['balance'] < 0 ? $this->h(-$m['balance']) : '—';

        return [
            'cliente'       => $project->customer->name ?? '—',
            'contrato'      => $project->code ?? ($project->name ?? ('#' . $project->id)),
            'periodo'       => $this->periodoLabel($m['basis']),
            'limite'        => $this->h($m['available']),
            'aprovadas'     => $this->h($m['approved']),
            'consumidas'    => $this->h($m['consumed']),
            'saldo'         => $saldo,
            'excedente'     => $excedente,
            'percentual'    => $this->pct($m['percentual']),
            'classificacao' => $this->classification($band) . ' (' . $band . '%)',
            'executivo'     => $this->executivoLabel($project),
        ];
    }

    private function periodoLabel(string $basis): string
    {
        return match ($basis) {
            'monthly' => 'Banco de Horas Mensal — saldo acumulado do contrato',
            'closed'  => 'Contrato fechado — total contratado',
            default   => 'Banco de Horas Fixo — total contratado',
        };
    }

    private function executivoLabel(Project $project): string
    {
        $exec = $project->contract->executivoConta
            ?? optional($project->customer)->executive
            ?? null;
        if (!$exec) {
            return '—';
        }
        $email = $exec->email ? ' (' . $exec->email . ')' : '';
        return trim(($exec->name ?? '') . $email) ?: '—';
    }

    private function loadProject(int $projectId): ?Project
    {
        return Project::with([
            'contractType',
            'contract:id,customer_id,executivo_conta_id',
            'contract.executivoConta:id,name,email',
            'customer:id,name,executive_id',
            'customer.executive:id,name,email',
            'childProjects.contractType',
            'coordinators:id,name,email',
        ])->find($projectId);
    }

    private function h($v): string
    {
        return number_format((float) $v, 1, ',', '.') . ' h';
    }

    private function pct($v): string
    {
        return number_format((float) $v, 0, ',', '.') . '%';
    }
}
