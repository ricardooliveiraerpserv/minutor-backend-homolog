<?php

namespace App\Http\Controllers;

use App\Models\GmudPackage;
use App\Models\HelpDeskTicket;
use App\SourceCode\Gmud\ClientSourceService;
use App\SourceCode\Gmud\GmudPublishException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fonte ENVIADO PELO CLIENTE no chamado (e-mail/portal) → versionar no Git, reusando o motor da GMUD.
 * Listar: qualquer agente interno vê. Montar/publicar: só COORDENADOR ou ADMIN.
 */
class HelpDeskClientSourceController extends Controller
{
    public function __construct(private ClientSourceService $service)
    {
    }

    /** GET /help-desk/tickets/{ticket}/client-sources — anexos de fonte do chamado + pacotes do cliente. */
    public function index(Request $request, HelpDeskTicket $ticket): JsonResponse
    {
        $user = $request->user();
        if (!$user || $user->type === 'cliente') {
            return response()->json(['message' => 'Sem permissão.'], 403);
        }
        $packages = GmudPackage::where('ticket_id', $ticket->id)->where('origin', ClientSourceService::ORIGIN)
            ->orderByDesc('id')->get(['id', 'status', 'original_name', 'received_at', 'source_attachment_ids']);
        $candidates = $this->service->candidates($ticket);
        return response()->json(['data' => [
            'can_publish'  => ClientSourceService::canPublish($user),
            'has_customer' => (bool) $ticket->customer_id,
            'candidates'   => $candidates,
            'pending'      => count(array_filter($candidates, fn ($c) => !$c['published'])),
            'packages'     => $packages,
        ]]);
    }

    /** POST /help-desk/tickets/{ticket}/client-sources — monta o pacote com os anexos escolhidos. */
    public function store(Request $request, HelpDeskTicket $ticket): JsonResponse
    {
        if (!ClientSourceService::canPublish($request->user())) {
            return response()->json(['message' => 'Só coordenador ou administrador pode versionar fonte enviado pelo cliente.'], 403);
        }
        $data = $request->validate([
            'attachment_ids'   => ['required', 'array', 'min:1', 'max:50'],
            'attachment_ids.*' => ['integer'],
        ]);
        try {
            $pkg = $this->service->buildPackage($ticket, $data['attachment_ids'], $request->user());
        } catch (GmudPublishException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['data' => ['id' => $pkg->id, 'status' => $pkg->status]], 201);
    }
}
