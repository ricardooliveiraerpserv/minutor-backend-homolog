<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesEnvMembership;
use App\Models\Attachment;
use App\Models\EnvAccessLog;
use App\Models\EnvCertificate;
use App\Models\EnvHistory;
use App\Models\EnvSecret;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Certificados A1 de um ambiente. Metadados/validade em CLARO; a SENHA do PFX é
 * segredo (env_secrets, via /reveal). O ARQUIVO .pfx é cifrado no client e sobe
 * como anexo .enc (entity_type ENV_CERT_PFX) — servidor nunca vê a chave privada.
 * A listagem informa o attachment id do PFX (p/ o client baixar e decifrar).
 */
class EnvironmentCertificateController extends Controller
{
    use ResolvesEnvMembership;

    private function pfxAttachmentId(int $certId): ?int
    {
        return Attachment::where('entity_type', 'ENV_CERT_PFX')
            ->where('entity_id', $certId)
            ->whereNull('deleted_at')
            ->latest('id')->value('id');
    }

    public function index(Request $request, int $envId): JsonResponse
    {
        $this->guardInternal($request);
        $env = $this->envWithMembership($request, $envId);

        $rows = EnvCertificate::with('responsible:id,name')
            ->where('environment_id', $env->id)
            ->orderBy('valid_to')
            ->get()
            ->map(fn ($c) => [
                'id'                 => $c->id,
                'name'               => $c->name,
                'type'               => $c->type,
                'issuer'             => $c->issuer,
                'valid_from'         => $c->valid_from?->toDateString(),
                'valid_to'           => $c->valid_to?->toDateString(),
                'days_to_expire'     => $c->valid_to ? now()->startOfDay()->diffInDays($c->valid_to, false) : null,
                'has_pfx_password'   => $c->pfx_pass_secret_id !== null,
                'pfx_pass_secret_id' => $c->pfx_pass_secret_id,
                'pfx_attachment_id'  => $this->pfxAttachmentId($c->id),
                'responsible'        => $c->responsible?->only(['id', 'name']),
                'critical'           => $c->critical,
            ]);

        return response()->json($rows);
    }

    public function store(Request $request, int $envId): JsonResponse
    {
        $this->guardInternal($request);
        $env = $this->envAuthorized($request, $envId, 'manage');
        $data = $request->validate([
            'name'                => 'required|string|max:160',
            'type'                => 'sometimes|in:A1,A3',
            'issuer'              => 'nullable|string|max:200',
            'subject'             => 'nullable|string|max:200',
            'thumbprint'          => 'nullable|string|max:120',
            'valid_from'          => 'nullable|date',
            'valid_to'            => 'nullable|date',
            'responsible_user_id' => 'nullable|integer|exists:users,id',
            'pfx_pass_data'       => 'nullable|string|max:102400', // senha do PFX cifrada no client
            'critical'            => 'sometimes|boolean',
            'notes'               => 'nullable|string|max:2000',
        ]);

        $cert = DB::transaction(function () use ($request, $env, $data) {
            $secretId = $this->syncSecret($request, $env, null, $data['pfx_pass_data'] ?? null, 'pfx_pass', $data['critical'] ?? false);

            return EnvCertificate::create([
                'environment_id'      => $env->id,
                'name'                => $data['name'],
                'type'                => $data['type'] ?? 'A1',
                'issuer'              => $data['issuer'] ?? null,
                'subject'             => $data['subject'] ?? null,
                'thumbprint'          => $data['thumbprint'] ?? null,
                'valid_from'          => $data['valid_from'] ?? null,
                'valid_to'            => $data['valid_to'] ?? null,
                'responsible_user_id' => $data['responsible_user_id'] ?? null,
                'pfx_pass_secret_id'  => $secretId,
                'critical'            => $data['critical'] ?? false,
                'notes'               => $data['notes'] ?? null,
                'created_by'          => $request->user()->id,
            ]);
        });
        EnvAccessLog::record($request, 'certificate_create', ['environment_id' => $env->id, 'item_label' => $cert->name]);
        EnvHistory::log($env->id, $request->user()->id, 'certificate', "Certificado \"{$cert->name}\" cadastrado");

        return response()->json(['id' => $cert->id], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->guardInternal($request);
        $cert = EnvCertificate::findOrFail($id);
        $env = $this->envAuthorized($request, $cert->environment_id, 'manage');
        $data = $request->validate([
            'name'                => 'sometimes|string|max:160',
            'type'                => 'sometimes|in:A1,A3',
            'issuer'              => 'nullable|string|max:200',
            'subject'             => 'nullable|string|max:200',
            'thumbprint'          => 'nullable|string|max:120',
            'valid_from'          => 'nullable|date',
            'valid_to'            => 'nullable|date',
            'responsible_user_id' => 'nullable|integer|exists:users,id',
            'pfx_pass_data'       => 'nullable|string|max:102400',
            'critical'            => 'sometimes|boolean',
            'notes'               => 'nullable|string|max:2000',
        ]);

        DB::transaction(function () use ($request, $cert, $env, $data) {
            $cert->pfx_pass_secret_id = $this->syncSecret($request, $env, $cert->pfx_pass_secret_id, $data['pfx_pass_data'] ?? null, 'pfx_pass', $data['critical'] ?? $cert->critical);
            $cert->fill(collect($data)->only(['name', 'type', 'issuer', 'subject', 'thumbprint', 'valid_from', 'valid_to', 'responsible_user_id', 'critical', 'notes'])->toArray());
            $cert->save();
        });
        EnvAccessLog::record($request, 'certificate_update', ['environment_id' => $env->id, 'item_label' => $cert->name]);

        return response()->json(['updated' => true]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->guardInternal($request);
        $cert = EnvCertificate::findOrFail($id);
        $env = $this->envAuthorized($request, $cert->environment_id, 'manage');
        EnvAccessLog::record($request, 'certificate_delete', ['environment_id' => $env->id, 'item_label' => $cert->name]);
        if ($cert->pfx_pass_secret_id) {
            EnvSecret::where('id', $cert->pfx_pass_secret_id)->delete();
        }
        $cert->delete();

        return response()->json(['deleted' => true]);
    }

    /** Histórico de eventos de negócio do ambiente. */
    public function history(Request $request, int $envId): JsonResponse
    {
        $this->guardInternal($request);
        $env = $this->envWithMembership($request, $envId);

        $rows = EnvHistory::with('user:id,name')
            ->where('environment_id', $env->id)
            ->orderByDesc('created_at')->limit(100)->get()
            ->map(fn ($h) => [
                'kind'        => $h->kind,
                'description' => $h->description,
                'user'        => $h->user?->name,
                'created_at'  => $h->created_at,
            ]);

        return response()->json($rows);
    }
}
