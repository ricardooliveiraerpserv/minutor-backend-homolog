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
    // Auditoria COMPLETA: loga toda alteração de campo do projeto, exceto derivados/sistema
    // (recalculados automaticamente ou sem valor de auditoria).
    private array $auditBlacklist = [
        'accumulated_sold_hours', // recalculado pelo próprio observer
        'created_at', 'updated_at', 'deleted_at',
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

        // Auditoria completa: registra TODOS os campos alterados (menos os da blacklist).
        foreach (array_keys($project->getChanges()) as $field) {
            if (in_array($field, $this->auditBlacklist, true)) {
                continue;
            }
            ProjectChangeLog::create([
                'project_id' => $project->id,
                'changed_by' => $userId,
                'field_name' => $field,
                'old_value'  => $this->stringifyValue($project->getOriginal($field)),
                'new_value'  => $this->stringifyValue($project->$field),
                'reason'     => null,
            ]);
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

    /** Converte qualquer valor para string armazenável em old_value/new_value. */
    private function stringifyValue($value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        return (string) $value;
    }
}
