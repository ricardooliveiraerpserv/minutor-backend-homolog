<?php

namespace App\Observers;

use App\Models\Project;
use App\Models\ProjectChangeLog;
use Illuminate\Support\Facades\Auth;

class ProjectObserver
{
    /**
     * Campos sensíveis que serão rastreados para histórico
     *
     * @var array<string>
     */
    private array $trackedFields = [
        'project_value',
        'hourly_rate',
        'sold_hours',
        'hour_contribution',
        'exceeded_hour_contribution',
        'consultant_hours',
        'coordinator_hours',
        'additional_hourly_rate',
        'max_expense_per_consultant',
        'unlimited_expense',
        'expense_responsible_party',
    ];

    /**
     * Handle the Project "created" event.
     */
    public function created(Project $project): void
    {
        // Não registra histórico na criação
        
        // Calcular accumulated_sold_hours na criação se for Banco de Horas Mensal
        // Recarregar o relacionamento contractType se necessário
        if ($project->contract_type_id) {
            $project->load('contractType');
        }
        
        // Atualizar o campo accumulated_sold_hours (skipObserver=true para evitar loop)
        try {
            $project->updateAccumulatedSoldHours(null, true);
        } catch (\Exception $e) {
            \Log::warning('ProjectObserver@created: falha ao calcular accumulated_sold_hours', ['error' => $e->getMessage(), 'project_id' => $project->id]);
        }
    }

    /**
     * Handle the Project "updated" event.
     * Este evento é disparado APÓS a atualização.
     */
    public function updated(Project $project): void
    {
        // Obter usuário autenticado
        $userId = Auth::id();

        // Se não houver usuário autenticado (ex: comandos CLI), não registra
        if (!$userId) {
            return;
        }

        // Usar isDirty() e getOriginal() para detectar mudanças
        // O Laravel mantém os valores originais disponíveis mesmo após a atualização
        foreach ($this->trackedFields as $field) {
            // Verificar se o campo foi alterado
            if ($project->wasChanged($field)) {
                // Obter valores antigo e novo
                $oldValue = $project->getOriginal($field);
                $newValue = $project->$field;

                // Registrar no histórico
                ProjectChangeLog::create([
                    'project_id' => $project->id,
                    'changed_by' => $userId,
                    'field_name' => $field,
                    'old_value' => $oldValue,
                    'new_value' => $newValue,
                    'reason' => null, // Pode ser implementado capturando do request se necessário
                ]);
            }
        }

        // Sempre recalcular accumulated_sold_hours se for Banco de Horas Mensal
        // Isso garante que o valor esteja atualizado mesmo que apenas outros campos tenham mudado
        // (o valor acumulado muda com o passar do tempo)
        
        // Recarregar o relacionamento contractType se necessário
        if ($project->wasChanged('contract_type_id') || !$project->relationLoaded('contractType')) {
            $project->load('contractType');
        }
        
        // Se for Banco de Horas Mensal, sempre recalcular
        if ($project->isBankHoursMonthly()) {
            try {
                $project->updateAccumulatedSoldHours(null, true);
            } catch (\Exception $e) {
                \Log::warning('ProjectObserver@updated: falha ao atualizar accumulated_sold_hours', ['error' => $e->getMessage(), 'project_id' => $project->id]);
            }
        } elseif ($project->wasChanged('contract_type_id')) {
            try {
                $project->updateAccumulatedSoldHours(null, true);
            } catch (\Exception $e) {
                \Log::warning('ProjectObserver@updated: falha ao limpar accumulated_sold_hours', ['error' => $e->getMessage(), 'project_id' => $project->id]);
            }
        }
    }

    /**
     * Verifica se houve mudança entre os valores, considerando null
     *
     * @param mixed $oldValue
     * @param mixed $newValue
     * @return bool
     */
    private function hasChanged($oldValue, $newValue): bool
    {
        // Normalizar valores nulos e vazios
        $oldValue = $oldValue === '' ? null : $oldValue;
        $newValue = $newValue === '' ? null : $newValue;

        // Para valores booleanos, converter para comparação
        if (is_bool($oldValue) || is_bool($newValue)) {
            return (bool)$oldValue !== (bool)$newValue;
        }

        // Para valores numéricos, converter para float para comparação precisa
        if (is_numeric($oldValue) || is_numeric($newValue)) {
            return (float)$oldValue !== (float)$newValue;
        }

        // Comparação padrão
        return $oldValue !== $newValue;
    }

    /**
     * Handle the Project "deleted" event.
     */
    public function deleted(Project $project): void
    {
        // Não registra histórico na exclusão
    }

    /**
     * Handle the Project "restored" event.
     */
    public function restored(Project $project): void
    {
        // Não registra histórico na restauração
    }

    /**
     * Handle the Project "force deleted" event.
     */
    public function forceDeleted(Project $project): void
    {
        // Não registra histórico na exclusão forçada
    }
}
