<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskGroup;
use App\Models\TaskGroupItem;
use Illuminate\Support\Carbon;

/** Gera as tasks individuais das rotinas (task_groups) para uma data, respeitando a recorrência. */
class RoutineGenerator
{
    /** Gera para todos os grupos ativos numa data. Retorna nº de tasks criadas. */
    public function generateAll(?Carbon $date = null): int
    {
        $date ??= now()->startOfDay();
        $created = 0;
        TaskGroup::where('active', true)->with(['users:id', 'items'])->get()
            ->each(function (TaskGroup $g) use ($date, &$created) {
                $created += $this->generateGroup($g, $date);
            });
        return $created;
    }

    /** Gera as tasks de UM grupo numa data (dedup por item+usuário+dia). */
    public function generateGroup(TaskGroup $g, Carbon $date): int
    {
        $created = 0;
        $userIds = $g->users->pluck('id');
        if ($userIds->isEmpty()) return 0;

        foreach ($g->items as $item) {
            if (!$this->itemDueOn($item, $date)) continue;
            foreach ($userIds as $uid) {
                $exists = Task::where('group_item_id', $item->id)
                    ->where('assigned_to', $uid)
                    ->whereDate('due_date', $date->toDateString())->exists();
                if ($exists) continue;

                Task::create([
                    'user_id'         => $uid,
                    'created_by'      => $g->owner_id,
                    'assigned_to'     => $uid,
                    'group_item_id'   => $item->id,
                    'title'           => $item->titulo,
                    'type'            => $item->tipo,
                    'priority'        => $item->priority,
                    'due_date'        => $date->toDateString(),
                    'due_time'        => $item->hora_padrao,
                    'recurrence_type' => 'none',  // a recorrência é da rotina (geração diária), não da task
                    'completed'       => false,
                ]);
                $created++;
            }
        }
        return $created;
    }

    /** O item-modelo vence nesta data? (daily/weekly por dias da semana, monthly = dia 1). */
    private function itemDueOn(TaskGroupItem $item, Carbon $date): bool
    {
        $days = array_map('intval', (array) ($item->recurrence_weekdays ?? []));
        return match ($item->recorrencia) {
            'daily'   => empty($days) ? true : in_array($date->dayOfWeek, $days, true),
            'weekly'  => !empty($days) && in_array($date->dayOfWeek, $days, true),
            'monthly' => $date->day === 1,
            default   => false,
        };
    }
}
