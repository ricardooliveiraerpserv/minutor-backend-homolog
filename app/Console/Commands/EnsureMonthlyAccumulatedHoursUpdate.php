<?php

namespace App\Console\Commands;

use App\Jobs\UpdateAccumulatedSoldHoursJob;
use App\Models\SystemSetting;
use Illuminate\Console\Command;
use Carbon\Carbon;

class EnsureMonthlyAccumulatedHoursUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'projects:ensure-monthly-update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verifica se accumulated_sold_hours foi atualizado no mês atual e executa se necessário';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔍 Verificando se accumulated_sold_hours foi atualizado este mês...');

        $lastExecution = SystemSetting::get(UpdateAccumulatedSoldHoursJob::LAST_EXECUTION_KEY);

        if (!$lastExecution) {
            $this->warn('⚠️  Nenhuma execução anterior encontrada. Executando agora...');
            UpdateAccumulatedSoldHoursJob::dispatch();
            $this->info('✅ Job despachado para execução imediata.');
            return 0;
        }

        $lastExecutionDate = Carbon::parse($lastExecution);
        $now = Carbon::now();
        
        // Verificar se a última execução foi neste mês
        $isCurrentMonth = $lastExecutionDate->year === $now->year && 
                         $lastExecutionDate->month === $now->month;

        if ($isCurrentMonth) {
            $this->info("✅ Job já foi executado este mês em: {$lastExecutionDate->format('d/m/Y H:i:s')}");
            return 0;
        }

        // Verificar quantos dias se passaram desde a última execução
        $daysSinceLastExecution = $lastExecutionDate->diffInDays($now);
        
        $this->warn("⚠️  Última execução foi há {$daysSinceLastExecution} dias ({$lastExecutionDate->format('d/m/Y H:i:s')})");
        $this->warn("⚠️  Mês atual: {$now->format('m/Y')} | Última execução: {$lastExecutionDate->format('m/Y')}");
        
        // Se passou mais de 2 dias do início do mês, executar imediatamente
        $dayOfMonth = $now->day;
        
        if ($dayOfMonth > 2 || !$isCurrentMonth) {
            $this->info('🚀 Executando job agora para garantir atualização mensal...');
            UpdateAccumulatedSoldHoursJob::dispatch();
            $this->info('✅ Job despachado para execução imediata.');
            
            return 0;
        }

        $this->info('ℹ️  Ainda estamos no início do mês. Aguardando execução agendada...');
        return 0;
    }
}
