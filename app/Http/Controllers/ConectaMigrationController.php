<?php

namespace App\Http\Controllers;

use App\Jobs\PushTicketToConectaJob;
use App\Models\ConectaTicketMigrationLog;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Painel da migração de clientes p/ a Conecta (tela /migracao-conecta): lista o log de tickets
 * enviados/entrados e permite reenviar. SÓ ADMIN. A migração em si é o
 * CustomerController::migrateToConecta. Ver [[project_minutor_conecta_client_migration]].
 */
class ConectaMigrationController extends Controller
{
    private function denyIfNotAdmin(Request $request): ?JsonResponse
    {
        if (! $request->user() || ! $request->user()->isAdmin()) {
            return response()->json(['message' => 'Apenas o administrador pode acessar a migração Conecta.'], 403);
        }
        return null;
    }

    /** Lista paginada do log (filtros: customer_id, result, kind, search). */
    public function log(Request $request): JsonResponse
    {
        if ($deny = $this->denyIfNotAdmin($request)) {
            return $deny;
        }

        $q = ConectaTicketMigrationLog::query()->with('customer:id,name')->orderByDesc('id');

        if ($request->filled('customer_id')) {
            $q->where('customer_id', (int) $request->input('customer_id'));
        }
        if ($request->filled('result')) {
            $q->where('result', (string) $request->input('result'));
        }
        if ($request->filled('kind')) {
            $q->where('kind', (string) $request->input('kind'));
        }
        if ($request->filled('search')) {
            $s = trim((string) $request->input('search'));
            $q->where(function ($w) use ($s) {
                $w->where('subject', 'ilike', "%{$s}%")
                    ->orWhere('external_ref', 'ilike', "%{$s}%")
                    ->orWhere('movidesk_id', 'ilike', "%{$s}%");
            });
        }

        return response()->json($q->paginate(min(100, (int) $request->input('per_page', 30))));
    }

    /** Contadores p/ o cabeçalho da tela. */
    public function summary(Request $request): JsonResponse
    {
        if ($deny = $this->denyIfNotAdmin($request)) {
            return $deny;
        }

        return response()->json([
            'migrated_customers' => Customer::whereNotNull('migrated_to_conecta_at')->count(),
            'total'              => ConectaTicketMigrationLog::count(),
            'ok'                 => ConectaTicketMigrationLog::where('result', 'ok')->count(),
            'routed'             => ConectaTicketMigrationLog::where('result', 'routed')->count(),
            'error'              => ConectaTicketMigrationLog::where('result', 'error')->count(),
            'pending'            => ConectaTicketMigrationLog::where('result', 'pending')->count(),
        ]);
    }

    /** Reenfileira o push de um ticket (linhas com ticket de origem na ERPSERV). */
    public function resend(Request $request, ConectaTicketMigrationLog $log): JsonResponse
    {
        if ($deny = $this->denyIfNotAdmin($request)) {
            return $deny;
        }
        if (! $log->erpserv_ticket_id) {
            return response()->json(['message' => 'Log de entrada (Movidesk) — a Conecta importa sozinha; nada a reenviar.'], 422);
        }
        PushTicketToConectaJob::dispatch((int) $log->erpserv_ticket_id, $log->kind ?: 'legado');

        return response()->json(['success' => true, 'message' => 'Reenvio enfileirado.']);
    }
}
