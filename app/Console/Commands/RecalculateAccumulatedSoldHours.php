<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Jobs\UpdateAccumulatedSoldHoursJob;
use Illuminate\Console\Command;

class RecalculateAccumulatedSoldHours extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'projects:recalculate-accumulated-hours 
                            {--project-id= : ID específico do projeto para recalcular}
                            {--all : Recalcular todos os projetos (padrão: apenas Banco de Horas Mensal)}
                            {--force : Forçar recálculo mesmo se o valor já estiver preenchido}
                            {--async : Executar de forma assíncrona usando queue}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recalcula o campo accumulated_sold_hours para projetos do tipo Banco de Horas Mensal';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $async = $this->option('async');
        $projectId = $this->option('project-id');
        $force = $this->option('force');

        // Se for assíncrono, despachar o Job
        if ($async) {
            $this->info('🚀 Despachando job assíncrono para atualização de horas acumuladas...');
            
            UpdateAccumulatedSoldHoursJob::dispatch($projectId, $force);
            
            $this->info('✅ Job despachado com sucesso!');
            $this->info('ℹ️  O processamento será feito em background. Verifique os logs para acompanhar o progresso.');
            return 0;
        }

        // Execução síncrona (código original)
        $this->info('🔄 Iniciando recálculo de horas acumuladas...');

        $allProjects = $this->option('all');

        // Construir query
        $query = Project::with('contractType');

        if ($projectId) {
            $query->where('id', $projectId);
            $this->info("📌 Recalculando apenas projeto ID: {$projectId}");
        } elseif (!$allProjects) {
            // Filtrar apenas projetos do tipo "Banco de Horas Mensal"
            $query->whereHas('contractType', function ($q) {
                $q->whereRaw('LOWER(TRIM(name)) = ?', ['banco de horas mensal']);
            });
            $this->info('📊 Recalculando apenas projetos do tipo "Banco de Horas Mensal"');
        } else {
            $this->info('📊 Recalculando TODOS os projetos');
        }

        $projects = $query->get();
        $total = $projects->count();

        if ($total === 0) {
            $this->warn('⚠️  Nenhum projeto encontrado para recalcular.');
            return 0;
        }

        $this->info("📋 Total de projetos encontrados: {$total}");
        $this->newLine();

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $updated = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($projects as $project) {
            try {
                // Verificar se deve recalcular
                if (!$force && $project->accumulated_sold_hours !== null && !$allProjects) {
                    // Se não for forçar e já tem valor, pular (a menos que seja --all)
                    $skipped++;
                    $bar->advance();
                    continue;
                }

                // Recarregar relacionamento se necessário
                if (!$project->relationLoaded('contractType') && $project->contract_type_id) {
                    $project->load('contractType');
                }

                // Atualizar horas acumuladas
                $result = $project->updateAccumulatedSoldHours(null, true);

                if ($result) {
                    $updated++;
                } else {
                    $skipped++;
                }
            } catch (\Exception $e) {
                $errors++;
                $this->newLine();
                $this->error("❌ Erro no projeto ID {$project->id}: {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // Resumo
        $this->info('✨ Recálculo concluído!');
        $this->table(
            ['Status', 'Quantidade'],
            [
                ['Atualizados', $updated],
                ['Ignorados', $skipped],
                ['Erros', $errors],
                ['Total', $total],
            ]
        );

        if ($errors > 0) {
            return 1;
        }

        return 0;
    }
}
