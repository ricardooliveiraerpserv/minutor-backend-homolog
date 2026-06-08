<?php

namespace App\Http\Controllers;

use App\Workflows\WorkflowConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Central de Workflows: lista os workflows de e-mail e configura quem recebe
 * (por audiência/papel) e em qual canal (To/Cc), além de e-mails fixos extras.
 */
class WorkflowController extends Controller
{
    public function __construct(private WorkflowConfigService $config) {}

    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        return response()->json([
            'audiences' => $this->config->audiences(),
            'workflows' => $this->config->all(),
        ]);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        $this->ensureAdmin($request);

        $data = $request->validate([
            'audiences'              => 'array',
            'audiences.*'            => 'in:off,to,cc',
            'extra_emails'           => 'array',
            'extra_emails.*.email'   => 'required|email',
            'extra_emails.*.channel' => 'required|in:to,cc',
            'subject'                => 'nullable|string|max:255',
            'body'                   => 'nullable|string|max:5000',
        ]);

        $this->config->save(
            $key,
            $data['audiences'] ?? [],
            $data['extra_emails'] ?? [],
            $data['subject'] ?? null,
            $data['body'] ?? null,
        );

        return response()->json([
            'ok'        => true,
            'workflows' => $this->config->all(),
        ]);
    }

    public function test(Request $request, string $key, \App\Workflows\WorkflowTestSender $sender): JsonResponse
    {
        $this->ensureAdmin($request);
        $data = $request->validate(['email' => 'required|email']);

        try {
            $sender->send($key, $data['email']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => 'Falha ao enviar: ' . $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'message' => "E-mail de teste enviado para {$data['email']}."]);
    }

    private function ensureAdmin(Request $request): void
    {
        if (!optional($request->user())->isAdmin()) {
            abort(403, 'Apenas administradores podem configurar workflows.');
        }
    }
}
