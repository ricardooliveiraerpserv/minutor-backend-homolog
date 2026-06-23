<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectSequence;
use Illuminate\Support\Facades\DB;

class ProjectCodeService
{
    /**
     * Gera o código para um projeto pai.
     * Usa lockForUpdate() para evitar race conditions.
     *
     * Formato: XXX000-YY
     */
    public function generateParentCode(Customer $customer): array
    {
        // MOTOR ÚNICO (Fase 1.1): delega ao DocumentNumberService — mesma sequência (project_sequences),
        // mesma regra contínua por ano, agora com unicidade cross-entidade (projects/contracts/propostas/documents).
        $r = app(\App\Documents\DocumentNumberService::class)->reservar($customer->id, 'projeto');

        return [
            'code'           => $r['codigo'],
            'proj_sequence'  => $r['sequence'],
            'proj_year'      => $r['year'],
            'child_sequence' => null,
            'is_manual_code' => false,
        ];
    }

    /**
     * Gera o código para um projeto filho.
     * Incrementa child_sequence baseado no maior existente do pai.
     *
     * Formato: XXX000-YY-ZZ
     */
    public function generateChildCode(Project $parent): array
    {
        $lastChild = Project::withTrashed()
            ->where('parent_project_id', $parent->id)
            ->whereNotNull('child_sequence')
            ->max('child_sequence');

        $childSeq = ($lastChild ?? 0) + 1;

        do {
            $padded = str_pad($childSeq, 2, '0', STR_PAD_LEFT);
            $code   = $parent->code . '-' . $padded;
            if (Project::withTrashed()->where('code', $code)->exists()) {
                $childSeq++;
            } else {
                break;
            }
        } while (true);

        return [
            'code'           => $code,
            'proj_sequence'  => $parent->proj_sequence,
            'proj_year'      => $parent->proj_year,
            'child_sequence' => $childSeq,
            'is_manual_code' => false,
        ];
    }

    /**
     * Resolve o código a usar no store:
     *  - se veio código manual → valida unicidade e retorna como manual
     *  - se não veio → auto-gera
     */
    public function resolveForStore(?string $requestCode, Customer $customer, ?Project $parent): array
    {
        if ($requestCode && !Project::withTrashed()->where('code', $requestCode)->exists()) {
            return [
                'code'           => $requestCode,
                'proj_sequence'  => null,
                'proj_year'      => null,
                'child_sequence' => null,
                'is_manual_code' => true,
            ];
        }

        if ($parent) {
            return $this->generateChildCode($parent);
        }

        return $this->generateParentCode($customer);
    }
}
