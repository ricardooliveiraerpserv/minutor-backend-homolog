<?php

namespace App\Jobs;

use App\Models\Project;
use App\Models\Timesheet;
use App\Services\RateioHoursService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Re-distribui (rateia) TODOS os apontamentos-origem de um projeto-servidor conforme os
 * planos vigentes, PRESERVANDO os ajustados manualmente (rateio_overridden=true).
 *
 * Roda em FILA (não no request do savePlans) por dois motivos:
 *  - com muitos apontamentos, o re-sync síncrono podia estourar o timeout do request e
 *    ficar PARCIAL (uns pais com filhos, outros sem);
 *  - try/catch POR PAI: uma falha isolada num apontamento não aborta o restante (antes,
 *    uma exceção no meio do loop deixava todos os pais seguintes sem divisão).
 */
class ResyncRateioHoursJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;
    public $backoff = [30, 120, 300, 600, 1200];
    public $timeout = 1800;

    public function __construct(public int $projectId)
    {
    }

    public function handle(RateioHoursService $svc): void
    {
        $project = Project::find($this->projectId);
        if (!$project || !$project->is_rateio) {
            return;
        }

        $ok = 0;
        $err = 0;
        Timesheet::where('project_id', $project->id)
            ->whereNull('rateio_source_timesheet_id')
            ->where('rateio_overridden', false)
            ->with('project')
            ->chunkById(200, function ($parents) use ($svc, &$ok, &$err) {
                foreach ($parents as $parent) {
                    try {
                        $svc->sync($parent, null);
                        $ok++;
                    } catch (\Throwable $e) {
                        $err++;
                        Log::warning('ResyncRateioHoursJob: falha ao ratear apontamento', [
                            'timesheet_id' => $parent->id,
                            'error'        => $e->getMessage(),
                        ]);
                    }
                }
            });

        Log::info('ResyncRateioHoursJob concluído', [
            'project_id' => $project->id,
            'ok'         => $ok,
            'erros'      => $err,
        ]);
    }
}
