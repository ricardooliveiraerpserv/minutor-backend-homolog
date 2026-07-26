<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesEnvMembership;
use App\Models\Attachment;
use App\Models\EnvAccessLog;
use App\Models\EnvLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Links (portais) e Documentação (anexos não-secretos) de um ambiente. */
class EnvironmentLinkController extends Controller
{
    use ResolvesEnvMembership;

    public function index(Request $request, int $envId): JsonResponse
    {
        $this->guardInternal($request);
        $env = $this->envWithMembership($request, $envId);

        return response()->json(
            EnvLink::where('environment_id', $env->id)->orderBy('label')->get(['id', 'label', 'url', 'kind'])
        );
    }

    public function store(Request $request, int $envId): JsonResponse
    {
        $this->guardInternal($request);
        $env = $this->envWithMembership($request, $envId, 'write');
        $data = $request->validate([
            'label' => 'required|string|max:120',
            'url'   => 'required|string|max:1000',
            'kind'  => 'sometimes|string|max:20',
        ]);
        $link = EnvLink::create([
            'environment_id' => $env->id,
            'label'          => $data['label'],
            'url'            => $data['url'],
            'kind'           => $data['kind'] ?? 'portal',
            'created_by'     => $request->user()->id,
        ]);

        return response()->json(['id' => $link->id], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->guardInternal($request);
        $link = EnvLink::findOrFail($id);
        $this->envWithMembership($request, $link->environment_id, 'write');
        $link->delete();

        return response()->json(['deleted' => true]);
    }

    /** Documentação (anexos ENV_DOC) do ambiente — metadados p/ listar/baixar. */
    public function docs(Request $request, int $envId): JsonResponse
    {
        $this->guardInternal($request);
        $env = $this->envWithMembership($request, $envId);

        $rows = Attachment::where('entity_type', 'ENV_DOC')
            ->where('entity_id', $env->id)
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->get(['id', 'original_name', 'extension', 'size_bytes', 'uploaded_by', 'created_at'])
            ->map(fn ($a) => [
                'id'            => $a->id,
                'original_name' => $a->original_name,
                'extension'     => $a->extension,
                'size_bytes'    => $a->size_bytes,
                'created_at'    => $a->created_at,
            ]);

        return response()->json($rows);
    }
}
