<?php

namespace App\Documents;

use App\Models\Customer;
use App\Models\DocumentEvent;
use App\Models\ProjectSequence;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1.1 — MOTOR ÚNICO de numeração do Minutor.
 *
 * Máscara: PREFIXO + SEQUENCIAL(3) + '-' + ANO(2)  → ex. ESP012-26
 * Fonte única: tabela `project_sequences` (last_sequence por cliente) — a MESMA usada por projetos.
 *   → propostas, contratos, projetos e documentos consomem ESTE serviço (sem sequência paralela).
 * Regra de ano: CONTÍNUA (last_sequence nunca zera; ano é só sufixo) — idêntica à regra atual do Minutor.
 * Reserva atômica: DB::transaction + lockForUpdate.
 * Unicidade: valida em projects.code, contracts.project_code_preview, crm_proposals.codigo, documents.codigo.
 */
class DocumentNumberService
{
    /**
     * Reserva um código para o cliente. Consome a sequência UMA vez.
     * @return array{codigo:string, sequence:int, year:string, prefix:string}
     */
    public function reservar(int $customerId, string $tipoDocumento = 'documento', array $context = [], ?int $userId = null): array
    {
        $customer = Customer::findOrFail($customerId);
        if (!$customer->code_prefix) {
            throw new \RuntimeException("Cliente '{$customer->name}' não possui prefixo de código (code_prefix).");
        }

        $code = null; $sequence = null;
        $year = now()->format('y');

        DB::transaction(function () use ($customer, $tipoDocumento, $context, $userId, $year, &$code, &$sequence) {
            $seq = ProjectSequence::where('customer_id', $customer->id)->lockForUpdate()->first();
            if (!$seq) {
                $seq = ProjectSequence::create(['customer_id' => $customer->id, 'last_sequence' => 0]);
            }
            $prefix = strtoupper($customer->code_prefix);

            do {
                $seq->last_sequence += 1;                                    // CONTÍNUA — nunca zera por ano
                $padded = str_pad((string) $seq->last_sequence, 3, '0', STR_PAD_LEFT);
                $code   = $prefix . $padded . '-' . $year;
            } while ($this->codeExists($code));

            $seq->save();
            $sequence = $seq->last_sequence;

            // Auditoria: numero_reservado (item 7)
            DocumentEvent::create([
                'document_id'  => $context['document_id'] ?? null,
                'sequence_number' => 1,
                'event_type'   => 'numero_reservado',
                'codigo'       => $code,
                'entity_type'  => $context['entity_type'] ?? null,
                'entity_id'    => $context['entity_id'] ?? null,
                'meta'         => ['tipo_documento' => $tipoDocumento, 'sequence' => $sequence, 'year' => $year, 'customer_id' => $customer->id],
                'triggered_by' => $userId ?? auth()->id(),
            ]);
        });

        return ['codigo' => $code, 'sequence' => $sequence, 'year' => $year, 'prefix' => $customer->code_prefix];
    }

    /** Unicidade GLOBAL — todas as entidades portadoras de código (as que existirem). */
    public function codeExists(string $code): bool
    {
        if (Project::withTrashed()->where('code', $code)->exists()) {
            return true;
        }
        if (Schema::hasColumn('contracts', 'project_code_preview')
            && DB::table('contracts')->where('project_code_preview', $code)->exists()) {
            return true;
        }
        if (Schema::hasColumn('crm_proposals', 'codigo')
            && DB::table('crm_proposals')->where('codigo', $code)->exists()) {
            return true;
        }
        if (Schema::hasColumn('documents', 'codigo')
            && DB::table('documents')->where('codigo', $code)->exists()) {
            return true;
        }
        return false;
    }
}
