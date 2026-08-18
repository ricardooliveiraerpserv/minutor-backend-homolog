<?php

namespace App\Jobs;

use App\Services\ContractHoursConsumptionAlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Avalia, em 2º plano, se um contrato cruzou uma nova faixa de consumo de horas e,
 * em caso positivo, envia o alerta. Disparado após a aprovação de um apontamento
 * (Timesheet::approve, via DB::afterCommit) — NUNCA bloqueia a aprovação: todo o
 * trabalho e qualquer falha ficam contidos aqui / no histórico.
 */
class CheckContractHoursConsumptionAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [60, 300];
    public $timeout = 120;

    public function __construct(public int $projectId) {}

    public function handle(ContractHoursConsumptionAlertService $service): void
    {
        // Serializa avaliações concorrentes do mesmo projeto (aprovação em lote gera
        // vários disparos) para não correr risco de envio duplicado da mesma faixa.
        $lock = Cache::lock('hours_alert_project_' . $this->projectId, 30);

        try {
            $lock->block(10);
            $service->evaluateProject($this->projectId);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            // Outro job já está avaliando este projeto — a idempotência cobre.
            return;
        } catch (\Throwable $e) {
            Log::warning('[hours_alert] job falhou', [
                'project' => $this->projectId,
                'err'     => $e->getMessage(),
            ]);
        } finally {
            optional($lock)->release();
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('[hours_alert] job esgotou tentativas', [
            'project' => $this->projectId,
            'err'     => $e->getMessage(),
        ]);
    }
}
