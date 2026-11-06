<?php

namespace App\Services;

use App\Models\OkrArea;
use App\Models\OkrCycle;
use App\Models\OkrObjective;

/**
 * Consolidação de progresso: KR → Objetivo → Área → Corporativo. Usa médias
 * PONDERADAS por peso (weight, default 1); NUNCA soma cega entre unidades. O
 * progresso corporativo pondera os objetivos de área vinculados (desdobramento).
 */
class OkrConsolidationService
{
    public function __construct(private OkrProgressService $progress) {}

    /** Progresso de um objetivo = média ponderada dos KRs (por weight). */
    public function objectiveProgress(OkrObjective $objective, array $thresholds): array
    {
        [$ach, $adh] = $this->weighted(
            $objective->keyResults->map(function ($kr) {
                $s = $this->progress->seriesForKeyResult($kr);
                return ['w' => $this->w($kr->weight), 'ach' => $s['summary']['achievement'], 'adh' => $s['summary']['adherence']];
            })->all()
        );
        return ['achievement' => $ach, 'adherence' => $adh, 'status' => $this->progress->status($adh, $thresholds)];
    }

    /** Progresso de uma área = média ponderada dos seus objetivos de área. */
    public function areaProgress(iterable $areaObjectives, array $thresholds): array
    {
        $parts = [];
        foreach ($areaObjectives as $obj) {
            $p = $this->objectiveProgress($obj, $thresholds);
            $parts[] = ['w' => $this->w($obj->weight), 'ach' => $p['achievement'], 'adh' => $p['adherence']];
        }
        [$ach, $adh] = $this->weighted($parts);
        return ['achievement' => $ach, 'adherence' => $adh, 'status' => $this->progress->status($adh, $thresholds)];
    }

    /**
     * Progresso de um objetivo CORPORATIVO = média ponderada dos objetivos de área
     * que o desdobram (children), por peso de cada filho. Critério explícito, sem
     * somar resultados de unidades diferentes.
     */
    public function corporateProgress(OkrObjective $corporate, array $thresholds): array
    {
        $parts = [];
        foreach ($corporate->children as $child) {
            $p = $this->objectiveProgress($child, $thresholds);
            $parts[] = ['w' => $this->w($child->weight), 'ach' => $p['achievement'], 'adh' => $p['adherence']];
        }
        // Se o corporativo tiver KRs próprios, eles também entram.
        foreach ($corporate->keyResults as $kr) {
            $s = $this->progress->seriesForKeyResult($kr);
            $parts[] = ['w' => $this->w($kr->weight), 'ach' => $s['summary']['achievement'], 'adh' => $s['summary']['adherence']];
        }
        [$ach, $adh] = $this->weighted($parts);
        return ['achievement' => $ach, 'adherence' => $adh, 'status' => $this->progress->status($adh, $thresholds)];
    }

    /**
     * Visão geral do ciclo para o dashboard executivo. Espera o ciclo com
     * objectives.keyResults.(monthlyTargets, monthlyResults) e objective.area eager-loaded.
     */
    public function cycleOverview(OkrCycle $cycle): array
    {
        $thresholds = $cycle->thresholds();
        $objectives = $cycle->objectives;
        $areaObjectives = $objectives->where('level', 'area');
        $corporate = $objectives->where('level', 'corporativo');

        $krCount = $objectives->sum(fn ($o) => $o->keyResults->count());

        // Progresso por área (agrupa os objetivos de área por okr_area_id).
        $byArea = [];
        foreach ($areaObjectives->groupBy('okr_area_id') as $areaId => $objs) {
            $p = $this->areaProgress($objs, $thresholds);
            $byArea[] = [
                'area_id' => $areaId,
                'area_name' => $objs->first()->area?->name,
                'objectives' => $objs->count(),
                'key_results' => $objs->sum(fn ($o) => $o->keyResults->count()),
                'achievement' => $p['achievement'],
                'adherence' => $p['adherence'],
                'status' => $p['status'],
            ];
        }
        usort($byArea, fn ($a, $b) => ($b['achievement'] ?? -1) <=> ($a['achievement'] ?? -1));

        // Objetivos corporativos com progresso desdobrado.
        $corporateRows = $corporate->map(function ($o) use ($thresholds) {
            $p = $this->corporateProgress($o, $thresholds);
            return ['id' => $o->id, 'title' => $o->title, 'achievement' => $p['achievement'], 'adherence' => $p['adherence'], 'status' => $p['status']];
        })->values();

        // KRs em risco (semáforo vermelho) e sem atualização (cinza).
        $atRisk = [];
        $noUpdate = 0;
        $onTrack = 0;
        foreach ($objectives as $o) {
            foreach ($o->keyResults as $kr) {
                $s = $this->progress->seriesForKeyResult($kr);
                $st = $s['summary']['status'];
                if ($st === OkrProgressService::STATUS_VERMELHO) {
                    $atRisk[] = ['id' => $kr->id, 'name' => $kr->name, 'objective_title' => $o->title, 'area_name' => $o->area?->name, 'adherence' => $s['summary']['adherence']];
                } elseif ($st === OkrProgressService::STATUS_CINZA) {
                    $noUpdate++;
                } elseif ($st === OkrProgressService::STATUS_VERDE) {
                    $onTrack++;
                }
            }
        }

        return [
            'cycle' => ['id' => $cycle->id, 'year' => $cycle->year, 'name' => $cycle->name, 'status' => $cycle->status],
            'totals' => [
                'objectives' => $objectives->count(),
                'corporate_objectives' => $corporate->count(),
                'area_objectives' => $areaObjectives->count(),
                'key_results' => $krCount,
                'objectives_done' => $objectives->where('status', 'concluido')->count(),
                'objectives_in_progress' => $objectives->where('status', 'em_andamento')->count(),
                'krs_on_track' => $onTrack,
                'krs_at_risk' => count($atRisk),
                'krs_no_update' => $noUpdate,
            ],
            'by_area' => $byArea,
            'corporate' => $corporateRows,
            'krs_at_risk' => $atRisk,
        ];
    }

    /** Atingimento geral do ciclo = média ponderada de TODOS os KRs (p/ comparativo entre anos). */
    public function overallAchievement(OkrCycle $cycle): ?float
    {
        $parts = [];
        foreach ($cycle->objectives as $o) {
            foreach ($o->keyResults as $kr) {
                $s = $this->progress->seriesForKeyResult($kr);
                $parts[] = ['w' => $this->w($kr->weight), 'ach' => $s['summary']['achievement'], 'adh' => null];
            }
        }
        return $this->weighted($parts)[0];
    }

    /** Média ponderada de achievement e adherence a partir de partes {w, ach, adh}. */
    private function weighted(array $parts): array
    {
        $wSumA = 0; $accA = 0; $wSumD = 0; $accD = 0;
        foreach ($parts as $p) {
            if ($p['ach'] !== null) { $wSumA += $p['w']; $accA += $p['w'] * $p['ach']; }
            if ($p['adh'] !== null) { $wSumD += $p['w']; $accD += $p['w'] * $p['adh']; }
        }
        return [
            $wSumA > 0 ? round($accA / $wSumA, 2) : null,
            $wSumD > 0 ? round($accD / $wSumD, 2) : null,
        ];
    }

    private function w(mixed $weight): float
    {
        $w = (float) ($weight ?? 1);
        return $w > 0 ? $w : 1.0;
    }
}
