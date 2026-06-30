<?php

namespace App\Console\Commands;

use App\Services\RoutineGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/** Gera as tasks diárias das Rotinas de Equipe (task_groups ativos). Roda 1x/dia de manhã. */
class GenerateRoutineTasks extends Command
{
    protected $signature = 'tasks:generate-routines {--date= : Data de referência YYYY-MM-DD (p/ teste)}';
    protected $description = 'Gera as tarefas das rotinas de equipe para a data (hoje por padrão)';

    public function handle(RoutineGenerator $gen): int
    {
        $date = $this->option('date') ? Carbon::parse($this->option('date'))->startOfDay() : now()->startOfDay();
        $n = $gen->generateAll($date);
        $this->info("rotinas: {$n} tarefa(s) geradas para {$date->toDateString()}");
        return self::SUCCESS;
    }
}
