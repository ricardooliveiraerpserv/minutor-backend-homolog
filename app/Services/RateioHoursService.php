<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Timesheet;

/**
 * Rateio de HORAS: distribui as horas de um apontamento feito num projeto-servidor
 * (is_rateio) para os projetos de destino, criando apontamentos-filhos
 * `is_billable_only=true` (contam como consumo do destino, não no pagamento do consultor).
 */
class RateioHoursService
{
    /**
     * Sincroniza os filhos de um apontamento de rateio. Apaga os antigos e recria.
     *
     * @param  array<int,array{target_project_id:int,minutes:int}>|null  $distribution
     *         Distribuição EXPLÍCITA (horas por destino, vinda do FE). Null = usa o % padrão.
     */
    public function sync(Timesheet $parent, ?array $distribution = null): void
    {
        $project = $parent->relationLoaded('project') ? $parent->project : Project::find($parent->project_id);
        // Sempre limpa os filhos antigos (idempotente); se não é mais rateio, fica limpo.
        Timesheet::where('rateio_source_timesheet_id', $parent->id)->forceDelete();

        if (!$project || !$project->is_rateio) {
            return;
        }

        $total = (int) $parent->effort_minutes;
        if ($total <= 0) {
            return;
        }

        $dateStr = $parent->date instanceof \Carbon\Carbon ? $parent->date->format('Y-m-d') : (string) $parent->date;

        foreach ($this->resolveSplits($project, $total, $distribution) as $split) {
            if ($split['minutes'] <= 0) {
                continue;
            }
            $target = Project::find($split['target_project_id']);
            if (!$target) {
                continue;
            }
            $child = new Timesheet([
                'project_id'     => $target->id,
                'user_id'        => $parent->user_id,
                'date'           => $dateStr,
                'effort_minutes' => $split['minutes'],
                'observation'    => 'Rateio de ' . $project->name . ' — apontamento #' . $parent->id,
                'ticket'         => $parent->ticket,
            ]);
            $child->customer_id                = $target->customer_id;
            $child->is_billable_only           = true;   // conta no destino, não no pagamento do consultor
            $child->status                     = $parent->status;
            $child->origin                     = $parent->origin;
            $child->rateio_source_timesheet_id = $parent->id;
            $child->company_id                 = $target->company_id ?? $parent->company_id;
            $child->save();
        }
    }

    /**
     * Resolve os minutos por destino. Se veio distribuição explícita do FE, usa ela;
     * senão distribui pelo % padrão (proporcional; sobra de arredondamento no último).
     *
     * @return array<int,array{target_project_id:int,minutes:int}>
     */
    private function resolveSplits(Project $project, int $totalMinutes, ?array $distribution): array
    {
        if (!empty($distribution)) {
            return collect($distribution)
                ->map(fn ($d) => [
                    'target_project_id' => (int) ($d['target_project_id'] ?? 0),
                    'minutes'           => (int) round($d['minutes'] ?? 0),
                ])
                ->filter(fn ($d) => $d['target_project_id'] > 0 && $d['minutes'] > 0)
                ->values()->all();
        }

        $targets = $project->rateioTargets()->get()->values();
        if ($targets->isEmpty()) {
            return [];
        }
        $out = [];
        $acc = 0;
        $n   = $targets->count();
        foreach ($targets as $i => $t) {
            $m = ($i === $n - 1)
                ? $totalMinutes - $acc
                : (int) floor($totalMinutes * (float) $t->percentual / 100);
            $acc += $m;
            $out[] = ['target_project_id' => (int) $t->target_project_id, 'minutes' => $m];
        }
        return $out;
    }

    /** Apaga os filhos de um apontamento (usado no destroy do pai). */
    public function clear(Timesheet $parent): void
    {
        Timesheet::where('rateio_source_timesheet_id', $parent->id)->forceDelete();
    }
}
