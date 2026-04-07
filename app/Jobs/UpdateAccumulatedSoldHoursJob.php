<?php

namespace App\Jobs;

use App\Models\Project;
use App\Models\SystemSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class UpdateAccumulatedSoldHoursJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Número máximo de tentativas
     * 
     * @var int
     */
    public $tries = 10;

    /**
     * Tempo de espera entre tentativas (em segundos)
     * Backoff exponencial: 1h, 2h, 4h, 8h, 16h, 24h, 24h, 24h, 24h, 24h
     * 
     * @var array
     */
    public $backoff = [3600, 7200, 14400, 28800, 57600, 86400, 86400, 86400, 86400, 86400];

    /**
     * Timeout do job em segundos (1 hora)
     * 
     * @var int
     */
    public $timeout = 3600;

    /**
     * Chave para rastrear última execução bem-sucedida
     */
    const LAST_EXECUTION_KEY = 'accumulated_sold_hours_last_execution';

    /**
     * ID específico do projeto para atualizar (opcional)
     * Se null, atualiza todos os projetos do tipo "Banco de Horas Mensal"
     *
     * @var int|null
     */
    public ?int $projectId;

    /**
     * Forçar recálculo mesmo se o valor já estiver preenchido
     *
     * @var bool
     */
    public bool $force;

    /**
     * Create a new job instance.
     *
     * @param int|null $projectId ID do projeto específico (opcional)
     * @param bool $force Forçar recálculo
     */
    public function __construct(?int $projectId = null, bool $force = false)
    {
        $this->projectId = $projectId;
        $this->force = $force;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $attempt = $this->attempts();
        
        Log::info('🔄 Iniciando atualização de horas acumuladas', [
            'project_id' => $this->projectId,
            'force' => $this->force,
            'attempt' => $attempt,
            'max_tries' => $this->tries
        ]);

        try {
            // Construir query
            $query = Project::with('contractType');

            if ($this->projectId) {
                $query->where('id', $this->projectId);
                Log::info("📌 Atualizando apenas projeto ID: {$this->projectId}");
            } else {
                // Filtrar apenas projetos do tipo "Banco de Horas Mensal"
                $query->whereHas('contractType', function ($q) {
                    $q->whereRaw('LOWER(TRIM(name)) = ?', ['banco de horas mensal']);
                });
                Log::info('📊 Atualizando todos os projetos do tipo "Banco de Horas Mensal"');
            }

            $projects = $query->get();
            $total = $projects->count();

            if ($total === 0) {
                Log::warning('⚠️  Nenhum projeto encontrado para atualizar.');
                // Mesmo sem projetos, marcar como executado para não ficar tentando
                $this->markAsExecuted();
                return;
            }

            Log::info("📋 Total de projetos encontrados: {$total}");

            $updated = 0;
            $skipped = 0;
            $errors = 0;
            $criticalErrors = 0;

            foreach ($projects as $project) {
                try {
                    // Recarregar relacionamento se necessário
                    if (!$project->relationLoaded('contractType') && $project->contract_type_id) {
                        $project->load('contractType');
                    }

                    // Atualizar horas acumuladas (skipObserver=true para evitar loop)
                    $result = $project->updateAccumulatedSoldHours(null, true);

                    if ($result) {
                        $updated++;
                        Log::debug("✅ Projeto ID {$project->id} atualizado: {$project->accumulated_sold_hours} horas");
                    } else {
                        $skipped++;
                    }
                } catch (\Exception $e) {
                    $errors++;
                    // Erros críticos são aqueles que impedem completamente a execução
                    if (str_contains($e->getMessage(), 'Connection') || 
                        str_contains($e->getMessage(), 'database') ||
                        str_contains($e->getMessage(), 'timeout')) {
                        $criticalErrors++;
                    }
                    
                    Log::error("❌ Erro ao atualizar projeto ID {$project->id}: {$e->getMessage()}", [
                        'project_id' => $project->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }

            // Se houve muitos erros críticos, lançar exceção para retry
            if ($criticalErrors > 0 && $criticalErrors >= ($total * 0.5)) {
                throw new \Exception("Muitos erros críticos ({$criticalErrors}/{$total}). Tentando novamente...");
            }

            // Marcar como executado com sucesso apenas se a maioria foi atualizada
            if ($updated > 0 || ($errors === 0 && $skipped < $total)) {
                $this->markAsExecuted();
            }

            Log::info('✨ Atualização concluída', [
                'updated' => $updated,
                'skipped' => $skipped,
                'errors' => $errors,
                'critical_errors' => $criticalErrors,
                'total' => $total,
                'success_rate' => $total > 0 ? round(($updated / $total) * 100, 2) . '%' : '0%'
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Erro crítico na execução do job', [
                'error' => $e->getMessage(),
                'attempt' => $attempt,
                'max_tries' => $this->tries
            ]);
            
            // Re-lançar exceção para que o Laravel faça retry
            throw $e;
        }
    }

    /**
     * Marcar job como executado com sucesso
     */
    protected function markAsExecuted(): void
    {
        $now = Carbon::now()->toDateTimeString();
        SystemSetting::set(
            self::LAST_EXECUTION_KEY,
            $now,
            'string',
            'jobs',
            'Data e hora da última execução bem-sucedida do UpdateAccumulatedSoldHoursJob'
        );
        
        Log::info('✅ Job marcado como executado com sucesso', [
            'execution_time' => $now
        ]);
    }

    /**
     * Handle a job failure.
     * 
     * @param \Throwable $exception
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('❌ Job falhou após todas as tentativas', [
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
            'max_tries' => $this->tries,
            'trace' => $exception->getTraceAsString()
        ]);

        // Mesmo após falhar todas as tentativas, tentar executar novamente em 24h
        // usando um novo job para garantir que será executado
        $this->retryLater();
    }

    /**
     * Agendar nova tentativa após falha completa
     */
    protected function retryLater(): void
    {
        // Despachar novo job para ser executado em 24 horas
        UpdateAccumulatedSoldHoursJob::dispatch($this->projectId, $this->force)
            ->delay(now()->addHours(24));
            
        Log::warning('⏰ Novo job agendado para 24 horas devido à falha completa', [
            'scheduled_for' => now()->addHours(24)->toDateTimeString()
        ]);
    }
}
