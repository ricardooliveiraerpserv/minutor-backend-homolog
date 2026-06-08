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
        ]);

        $this->config->save(
            $key,
            $data['audiences'] ?? [],
            $data['extra_emails'] ?? [],
        );

        return response()->json([
            'ok'        => true,
            'workflows' => $this->config->all(),
        ]);
    }

    private function ensureAdmin(Request $request): void
    {
        if (!optional($request->user())->isAdmin()) {
            abort(403, 'Apenas administradores podem configurar workflows.');
        }
    }
}
