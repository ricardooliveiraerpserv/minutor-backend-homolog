<?php

namespace App\Services;

use App\Models\Timesheet;
use App\Models\TimesheetOriginLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Emissor ERPSERV → Conecta: empurra (upsert) os apontamentos MIGRADOS pela Transferência de
 * Apontamentos para o espelho da Conecta (POST /api/v1/migration/transferred-hours). Tempo real
 * (via job na migração/aplicação/edição de origem). Best-effort. `removed=true` quando o apontamento
 * deixou de ser migrado (origem removida) ou foi excluído.
 */
class ConectaMigratedHoursPusher
{
    public function pushIds(array $ids): void
    {
        $url    = rtrim((string) config('services.conecta.api_url', ''), '/');
        $secret = (string) config('services.conecta.migration_secret', '');
        $ids    = array_values(array_unique(array_map('intval', $ids)));
        if ($url === '' || $secret === '' || empty($ids)) {
            return;
        }

        // withoutGlobalScopes inclui soft-deleted (p/ detectar remoção) e ignora CompanyScope (cross-empresa).
        $rows = Timesheet::withoutGlobalScopes()
            ->with(['user:id,name', 'project:id,code,name,hourly_rate', 'sourceCustomer:id,name', 'sourceProject:id,code,name'])
            ->whereIn('id', $ids)->get();
        $methods = TimesheetOriginLog::whereIn('timesheet_id', $ids)
            ->orderByDesc('id')->get()->groupBy('timesheet_id')->map(fn ($g) => $g->first()->method);

        $items = [];
        foreach ($rows as $t) {
            $migrated = (bool) $t->transfer_source_customer_id && ! $t->trashed();
            $rate  = $t->project && $t->project->hourly_rate !== null ? (float) $t->project->hourly_rate : null;
            $hours = round(((int) $t->effort_minutes) / 60, 2);
            $items[] = [
                'erpserv_timesheet_id'   => (int) $t->id,
                'removed'                => ! $migrated,
                'erpserv_company_id'     => $t->company_id,
                'date'                   => optional($t->date)->toDateString(),
                'entry_created_at'       => optional($t->created_at)->toIso8601String(),
                'effort_minutes'         => (int) $t->effort_minutes,
                'source_customer_erp_id' => $t->transfer_source_customer_id,
                'source_customer_name'   => optional($t->sourceCustomer)->name,
                'source_project_name'    => $t->sourceProject ? trim(($t->sourceProject->code ? $t->sourceProject->code . ' · ' : '') . $t->sourceProject->name) : null,
                'target_project_erp_id'  => $t->project_id,
                'target_project_name'    => $t->project ? trim(($t->project->code ? $t->project->code . ' · ' : '') . $t->project->name) : null,
                'hourly_rate'            => $rate,
                'value'                  => $rate !== null ? round($hours * $rate, 2) : null,
                'user_name'              => optional($t->user)->name,
                'origin_type'            => $t->origin,
                'ticket'                 => $t->ticket ?: null,
                'observation'            => Str::limit(trim(preg_replace('/\s+/', ' ', html_entity_decode(preg_replace('/<[^>]*>/', ' ', (string) $t->observation), ENT_QUOTES | ENT_HTML5))), 2000),
                'rule_id'                => $t->transfer_rule_id,
                'method'                 => $methods[$t->id] ?? null,
                'date_locked'            => (bool) $t->date_locked,
                'manual_edit'            => (bool) $t->manual_project_edit,
            ];
        }
        if (empty($items)) {
            return;
        }

        try {
            $resp = Http::withHeaders(['X-Migration-Secret' => $secret])->acceptJson()->timeout(60)
                ->post("{$url}/api/v1/migration/transferred-hours", ['items' => $items]);
            if (! $resp->successful()) {
                throw new \RuntimeException('HTTP ' . $resp->status() . ': ' . $resp->body());
            }
        } catch (\Throwable $e) {
            Log::warning('conecta.migrated_hours.push_failed', ['error' => $e->getMessage(), 'ids' => $ids]);
            throw $e; // deixa o job re-tentar
        }
    }
}
