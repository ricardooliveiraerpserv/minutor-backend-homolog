<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesEnvMembership;
use App\Models\EnvAccessLog;
use App\Models\EnvCredential;
use App\Models\EnvSecret;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Credenciais de um ambiente. LISTAGEM NÃO devolve o blob da senha (só metadados
 * CLARO + has_secret). A senha só sai via /environments/secrets/{id}/reveal enforced.
 */
class EnvironmentCredentialController extends Controller
{
    use ResolvesEnvMembership;

    public function index(Request $request, int $envId): JsonResponse
    {
        $this->guardInternal($request);
        $env = $this->envWithMembership($request, $envId);

        $rows = EnvCredential::with('responsible:id,name')
            ->where('environment_id', $env->id)
            ->orderBy('category')->orderBy('label')
            ->get()
            ->map(fn ($c) => [
                'id'                => $c->id,
                'category'          => $c->category,
                'label'             => $c->label,
                'username'          => $c->username,
                'url'               => $c->url,
                'has_secret'        => $c->secret_id !== null,
                'secret_id'         => $c->secret_id,       // p/ o client chamar /reveal
                'critical'          => $c->critical,
                'responsible'       => $c->responsible?->only(['id', 'name']),
                'last_rotated_at'   => $c->last_rotated_at,
                'rotate_every_days' => $c->rotate_every_days,
            ]);

        return response()->json($rows);
    }

    public function store(Request $request, int $envId): JsonResponse
    {
        $this->guardInternal($request);
        $env = $this->envAuthorized($request, $envId, 'manage');
        $data = $request->validate([
            'category'            => 'required|in:' . implode(',', EnvCredential::CATEGORIES),
            'label'               => 'required|string|max:120',
            'username'            => 'nullable|string|max:200',
            'url'                 => 'nullable|string|max:500',
            'password_data'       => 'nullable|string|max:102400', // blob v1. cifrado no client
            'critical'            => 'sometimes|boolean',
            'notes'               => 'nullable|string|max:2000',
            'responsible_user_id' => 'nullable|integer|exists:users,id',
            'key_version'         => 'sometimes|integer',
        ]);

        $cred = DB::transaction(function () use ($request, $env, $data) {
            $secretId = null;
            if (! empty($data['password_data'])) {
                $secretId = EnvSecret::create([
                    'environment_id' => $env->id,
                    'vault_id'       => $env->vault_id,
                    'kind'           => 'password',
                    'data'           => $data['password_data'],
                    'key_version'    => $data['key_version'] ?? 1,
                    'critical'       => $data['critical'] ?? false,
                    'created_by'     => $request->user()->id,
                    'updated_by'     => $request->user()->id,
                ])->id;
            }

            return EnvCredential::create([
                'environment_id'      => $env->id,
                'category'            => $data['category'],
                'label'               => $data['label'],
                'username'            => $data['username'] ?? null,
                'url'                 => $data['url'] ?? null,
                'secret_id'           => $secretId,
                'critical'            => $data['critical'] ?? false,
                'notes'               => $data['notes'] ?? null,
                'responsible_user_id' => $data['responsible_user_id'] ?? null,
                'last_rotated_at'     => $secretId ? now() : null,
                'created_by'          => $request->user()->id,
            ]);
        });
        EnvAccessLog::record($request, 'cred_create', ['environment_id' => $env->id, 'item_label' => $cred->label]);

        return response()->json(['id' => $cred->id], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->guardInternal($request);
        $cred = EnvCredential::findOrFail($id);
        $env = $this->envAuthorized($request, $cred->environment_id, 'manage');
        $data = $request->validate([
            'label'               => 'sometimes|string|max:120',
            'username'            => 'nullable|string|max:200',
            'url'                 => 'nullable|string|max:500',
            'password_data'       => 'nullable|string|max:102400', // se enviado, troca a senha
            'critical'            => 'sometimes|boolean',
            'notes'               => 'nullable|string|max:2000',
            'responsible_user_id' => 'nullable|integer|exists:users,id',
            'key_version'         => 'sometimes|integer',
        ]);

        DB::transaction(function () use ($request, $cred, $env, $data) {
            if (array_key_exists('password_data', $data) && ! empty($data['password_data'])) {
                if ($cred->secret_id) {
                    EnvSecret::where('id', $cred->secret_id)->update([
                        'data'        => $data['password_data'],
                        'key_version' => $data['key_version'] ?? 1,
                        'updated_by'  => $request->user()->id,
                        'critical'    => $data['critical'] ?? $cred->critical,
                    ]);
                } else {
                    $cred->secret_id = EnvSecret::create([
                        'environment_id' => $env->id,
                        'vault_id'       => $env->vault_id,
                        'kind'           => 'password',
                        'data'           => $data['password_data'],
                        'key_version'    => $data['key_version'] ?? 1,
                        'critical'       => $data['critical'] ?? false,
                        'created_by'     => $request->user()->id,
                        'updated_by'     => $request->user()->id,
                    ])->id;
                }
                $cred->last_rotated_at = now();
            }
            $cred->fill(collect($data)->only(['label', 'username', 'url', 'critical', 'notes', 'responsible_user_id'])->toArray());
            $cred->save();
        });
        EnvAccessLog::record($request, 'cred_update', ['environment_id' => $env->id, 'item_label' => $cred->label]);

        return response()->json(['updated' => true]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->guardInternal($request);
        $cred = EnvCredential::findOrFail($id);
        $env = $this->envAuthorized($request, $cred->environment_id, 'manage');
        EnvAccessLog::record($request, 'cred_delete', ['environment_id' => $env->id, 'item_label' => $cred->label]);
        if ($cred->secret_id) {
            EnvSecret::where('id', $cred->secret_id)->delete();
        }
        $cred->delete();

        return response()->json(['deleted' => true]);
    }
}
