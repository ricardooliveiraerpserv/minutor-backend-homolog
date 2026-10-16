<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\StageDelivery;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Serializa o cronograma de um projeto numa árvore RELATIVA (offsets de dias a
 * partir de uma âncora) e materializa essa árvore em outro projeto reencaixando
 * as datas a partir de uma data de início. Núcleo compartilhado por:
 *  - Modelos de cronograma (salvar/aplicar)
 *  - Copiar cronograma de outro projeto
 */
class CronogramaTemplateService
{
    /** Gera o payload relativo a partir do cronograma do projeto. */
    public function serialize(Project $project): array
    {
        $stages = $project->stages()
            ->with(['deliveries' => fn ($q) => $q->orderBy('order_index')])
            ->orderBy('order_index')
            ->get();

        // Âncora = data de início mais cedo do cronograma (etapa ou atividade).
        $dates = [];
        foreach ($stages as $st) {
            if ($st->stage_start_at)    $dates[] = Carbon::parse($st->stage_start_at);
            if ($st->expected_end_date) $dates[] = Carbon::parse($st->expected_end_date);
            foreach ($st->deliveries as $d) {
                if ($d->planned_start_at) $dates[] = Carbon::parse($d->planned_start_at);
                if ($d->due_date)         $dates[] = Carbon::parse($d->due_date);
            }
        }
        $anchor = count($dates) ? min($dates) : null;
        $off = fn ($date) => ($anchor && $date) ? (int) $anchor->diffInDays(Carbon::parse($date), false) : null;

        // Emite etapas de topo, cada uma seguida das suas sub-etapas (parent antes do filho).
        $top = $stages->whereNull('parent_stage_id')->sortBy('order_index');
        $childrenOf = fn ($id) => $stages->where('parent_stage_id', $id)->sortBy('order_index');

        $out = [];
        $emit = function (ProjectStage $st, ?string $parentRef) use (&$out, $off) {
            $out[] = [
                'ref'           => "s{$st->id}",
                'parent_ref'    => $parentRef,
                'name'          => $st->name,
                'hours_planned' => (float) ($st->hours_planned ?? 0),
                'start_offset'  => $off($st->stage_start_at),
                'end_offset'    => $off($st->expected_end_date),
                'deliveries'    => $st->deliveries->map(fn (StageDelivery $d) => [
                    'ref'             => "d{$d->id}",
                    'title'           => $d->title,
                    'hours_planned'   => (float) ($d->hours_planned ?? 0),
                    'priority'        => $d->priority,
                    'client_involved' => (bool) $d->client_involved,
                    'client_email'    => $d->client_email,
                    'extra_clients'   => $d->extra_clients,
                    'start_offset'    => $off($d->planned_start_at),
                    'end_offset'      => $off($d->due_date),
                    'depends_on_ref'  => $d->depends_on_delivery_id ? "d{$d->depends_on_delivery_id}" : null,
                    'dependency_type' => $d->dependency_type,
                ])->values()->all(),
            ];
        };

        foreach ($top as $etapa) {
            $emit($etapa, null);
            foreach ($childrenOf($etapa->id) as $sub) {
                $emit($sub, "s{$etapa->id}");
            }
        }

        return ['stages' => $out];
    }

    /**
     * Cria a árvore do payload no projeto de destino, ancorando em $startDate.
     * Preserva hierarquia, horas, cliente e dependências (remapeadas).
     */
    public function materialize(Project $target, array $payload, string $startDate): void
    {
        $anchor = Carbon::parse($startDate)->startOfDay();
        $date = fn ($offset) => $offset === null ? null : $anchor->copy()->addDays((int) $offset)->toDateString();

        $stages = $payload['stages'] ?? [];

        DB::transaction(function () use ($target, $stages, $date) {
            $stageMap = [];     // ref → ProjectStage
            $deliveryMap = [];  // ref → StageDelivery
            $depsToWire = [];   // [newDeliveryId => depends_on_ref]

            $topOrder = (int) $target->stages()->whereNull('parent_stage_id')->max('order_index');
            $subOrder = []; // parentId → counter

            foreach ($stages as $s) {
                $parent = $s['parent_ref'] ? ($stageMap[$s['parent_ref']] ?? null) : null;
                if ($parent) {
                    $subOrder[$parent->id] = ($subOrder[$parent->id] ?? 0) + 1;
                    $order = $subOrder[$parent->id];
                } else {
                    $order = ++$topOrder;
                }

                $stage = ProjectStage::create([
                    'project_id'        => $target->id,
                    'parent_stage_id'   => $parent?->id,
                    'name'              => $s['name'],
                    'hours_planned'     => $s['hours_planned'] ?? 0,
                    'status'            => 'active',
                    'order_index'       => $order,
                    'stage_start_at'    => $date($s['start_offset'] ?? null),
                    'expected_end_date' => $date($s['end_offset'] ?? null),
                ]);
                $stageMap[$s['ref']] = $stage;

                $di = 0;
                foreach (($s['deliveries'] ?? []) as $d) {
                    $delivery = StageDelivery::create([
                        'stage_id'         => $stage->id,
                        'title'            => $d['title'],
                        'hours_planned'    => $d['hours_planned'] ?? 0,
                        'priority'         => $d['priority'] ?? 'medium',
                        'status'           => 'backlog',
                        'order_index'      => ++$di,
                        'planned_start_at' => $date($d['start_offset'] ?? null),
                        'due_date'         => $date($d['end_offset'] ?? null),
                        'client_involved'  => $d['client_involved'] ?? false,
                        'client_email'     => $d['client_email'] ?? null,
                        'extra_clients'    => $d['extra_clients'] ?? null,
                    ]);
                    $deliveryMap[$d['ref']] = $delivery;
                    if (!empty($d['depends_on_ref'])) {
                        $depsToWire[$delivery->id] = ['ref' => $d['depends_on_ref'], 'type' => $d['dependency_type'] ?? 'FS'];
                    }
                }
            }

            // Religa dependências agora que todas as atividades existem.
            foreach ($depsToWire as $newId => $dep) {
                $pred = $deliveryMap[$dep['ref']] ?? null;
                if ($pred) {
                    StageDelivery::where('id', $newId)->update([
                        'depends_on_delivery_id' => $pred->id,
                        'dependency_type'        => $dep['type'],
                    ]);
                }
            }
        });

        $target->recalcExpectedEndFromSchedule();
    }
}
