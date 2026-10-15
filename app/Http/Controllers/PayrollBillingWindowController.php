<?php

namespace App\Http\Controllers;

use App\Models\PayrollBillingWindow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gerencia a JANELA DE PAGAMENTO de um colaborador (usuário ou parceiro) direto do cadastro:
 * definir até qual mês pagar (corte), retomar a partir de um mês (pode ser retroativo) ou limpar.
 * Útil p/ colaboradores JÁ inativos sem janela (ex.: Leandro) — basta definir o corte.
 */
class PayrollBillingWindowController extends Controller
{
    private function authorizeAdmin(Request $request): void
    {
        $u = $request->user();
        abort_unless($u && ($u->isAdmin() || (method_exists($u, 'isAdministrativo') && $u->isAdministrativo())), 403);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'holder_type' => 'required|in:user,partner',
            'holder_id'   => 'required|integer|min:1',
        ]);
    }

    /** GET /payroll-windows?holder_type=&holder_id= — resumo da janela. */
    public function show(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $v = $this->validated($request);
        return response()->json(['data' => PayrollBillingWindow::summary($v['holder_type'], (int) $v['holder_id'], now()->format('Y-m'))]);
    }

    /** POST /payroll-windows/close — paga até end_month (inclusivo). */
    public function close(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $v = $request->validate([
            'holder_type' => 'required|in:user,partner',
            'holder_id'   => 'required|integer|min:1',
            'end_month'   => 'required|regex:/^\d{4}-\d{2}$/',
        ]);
        PayrollBillingWindow::closeAt($v['holder_type'], (int) $v['holder_id'], $v['end_month'], 'Ajuste manual');
        return response()->json(['data' => PayrollBillingWindow::summary($v['holder_type'], (int) $v['holder_id'], now()->format('Y-m'))]);
    }

    /** POST /payroll-windows/resume — retoma a partir de from_month (pode ser retroativo). */
    public function resume(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $v = $request->validate([
            'holder_type' => 'required|in:user,partner',
            'holder_id'   => 'required|integer|min:1',
            'from_month'  => 'required|regex:/^\d{4}-\d{2}$/',
        ]);
        PayrollBillingWindow::resumeFrom($v['holder_type'], (int) $v['holder_id'], $v['from_month'], 'Ajuste manual');
        return response()->json(['data' => PayrollBillingWindow::summary($v['holder_type'], (int) $v['holder_id'], now()->format('Y-m'))]);
    }

    /** POST /payroll-windows/clear — remove todas as janelas (volta a pagar sempre). */
    public function clear(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $v = $this->validated($request);
        PayrollBillingWindow::where('holder_type', $v['holder_type'])->where('holder_id', (int) $v['holder_id'])->delete();
        return response()->json(['data' => PayrollBillingWindow::summary($v['holder_type'], (int) $v['holder_id'], now()->format('Y-m'))]);
    }
}
