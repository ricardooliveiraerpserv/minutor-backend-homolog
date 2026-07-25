<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesEnvMembership;
use App\Models\EnvAccessLog;
use App\Models\EnvAppserver;
use App\Models\EnvDatabase;
use App\Models\EnvSecret;
use App\Models\EnvVpn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Recursos de infra do ambiente: Banco, AppServer, VPN. Metadados em CLARO; a SENHA
 * de cada um vive em env_secrets (blob cifrado no client) e só sai via /reveal enforced.
 * A listagem NUNCA devolve o blob — só has_password + secret_id.
 */
class EnvironmentInfraController extends Controller
{
    use ResolvesEnvMembership;

    // ── Banco ─────────────────────────────────────────────────────────────────

    public function databases(Request $request, int $envId): JsonResponse
    {
        $this->guardInternal($request);
        $env = $this->envWithMembership($request, $envId);

        $rows = EnvDatabase::where('environment_id', $env->id)->orderBy('server')->get()->map(fn ($d) => [
            'id'          => $d->id,
            'engine'      => $d->engine,
            'server'      => $d->server,
            'port'        => $d->port,
            'instance'    => $d->instance,
            'database'    => $d->database,
            'username'    => $d->username,
            'has_password' => $d->password_secret_id !== null,
            'secret_id'   => $d->password_secret_id,
            'always_on'   => $d->always_on,
            'backup_info' => $d->backup_info,
            'critical'    => $d->critical,
        ]);

        return response()->json($rows);
    }

    public function storeDatabase(Request $request, int $envId): JsonResponse
    {
        $this->guardInternal($request);
        $env = $this->envWithMembership($request, $envId, 'write');
        $data = $request->validate([
            'engine'        => 'sometimes|in:sqlserver,postgres,oracle,mysql',
            'server'        => 'required|string|max:200',
            'port'          => 'nullable|integer',
            'instance'      => 'nullable|string|max:120',
            'database'      => 'nullable|string|max:120',
            'username'      => 'nullable|string|max:120',
            'password_data' => 'nullable|string|max:102400',
            'backup_info'   => 'nullable|array',
            'always_on'     => 'sometimes|boolean',
            'critical'      => 'sometimes|boolean',
            'notes'         => 'nullable|string|max:2000',
        ]);

        $db = DB::transaction(function () use ($request, $env, $data) {
            $secretId = $this->syncSecret($request, $env, null, $data['password_data'] ?? null, 'password', $data['critical'] ?? false);

            return EnvDatabase::create([
                'environment_id'     => $env->id,
                'engine'             => $data['engine'] ?? 'sqlserver',
                'server'             => $data['server'],
                'port'               => $data['port'] ?? null,
                'instance'           => $data['instance'] ?? null,
                'database'           => $data['database'] ?? null,
                'username'           => $data['username'] ?? null,
                'password_secret_id' => $secretId,
                'backup_info'        => $data['backup_info'] ?? null,
                'always_on'          => $data['always_on'] ?? false,
                'critical'           => $data['critical'] ?? false,
                'notes'              => $data['notes'] ?? null,
                'created_by'         => $request->user()->id,
            ]);
        });
        EnvAccessLog::record($request, 'database_create', ['environment_id' => $env->id, 'item_label' => $db->server]);

        return response()->json(['id' => $db->id], 201);
    }

    public function updateDatabase(Request $request, int $id): JsonResponse
    {
        $this->guardInternal($request);
        $db = EnvDatabase::findOrFail($id);
        $env = $this->envWithMembership($request, $db->environment_id, 'write');
        $data = $request->validate([
            'engine'        => 'sometimes|in:sqlserver,postgres,oracle,mysql',
            'server'        => 'sometimes|string|max:200',
            'port'          => 'nullable|integer',
            'instance'      => 'nullable|string|max:120',
            'database'      => 'nullable|string|max:120',
            'username'      => 'nullable|string|max:120',
            'password_data' => 'nullable|string|max:102400',
            'backup_info'   => 'nullable|array',
            'always_on'     => 'sometimes|boolean',
            'critical'      => 'sometimes|boolean',
            'notes'         => 'nullable|string|max:2000',
        ]);

        DB::transaction(function () use ($request, $db, $env, $data) {
            $db->password_secret_id = $this->syncSecret($request, $env, $db->password_secret_id, $data['password_data'] ?? null, 'password', $data['critical'] ?? $db->critical);
            $db->fill(collect($data)->only(['engine', 'server', 'port', 'instance', 'database', 'username', 'backup_info', 'always_on', 'critical', 'notes'])->toArray());
            $db->save();
        });
        EnvAccessLog::record($request, 'database_update', ['environment_id' => $env->id, 'item_label' => $db->server]);

        return response()->json(['updated' => true]);
    }

    public function destroyDatabase(Request $request, int $id): JsonResponse
    {
        $this->guardInternal($request);
        $db = EnvDatabase::findOrFail($id);
        $env = $this->envWithMembership($request, $db->environment_id, 'write');
        EnvAccessLog::record($request, 'database_delete', ['environment_id' => $env->id, 'item_label' => $db->server]);
        if ($db->password_secret_id) {
            EnvSecret::where('id', $db->password_secret_id)->delete();
        }
        $db->delete();

        return response()->json(['deleted' => true]);
    }

    // ── AppServer ─────────────────────────────────────────────────────────────

    public function appservers(Request $request, int $envId): JsonResponse
    {
        $this->guardInternal($request);
        $env = $this->envWithMembership($request, $envId);

        $rows = EnvAppserver::where('environment_id', $env->id)->orderBy('name')->get()->map(fn ($a) => [
            'id'        => $a->id,
            'name'      => $a->name,
            'version'   => $a->version,
            'build'     => $a->build,
            'patch'     => $a->patch,
            'root_path' => $a->root_path,
            'port'      => $a->port,
            'notes'     => $a->notes,
        ]);

        return response()->json($rows);
    }

    public function storeAppserver(Request $request, int $envId): JsonResponse
    {
        $this->guardInternal($request);
        $env = $this->envWithMembership($request, $envId, 'write');
        $data = $request->validate([
            'name'      => 'required|string|max:120',
            'version'   => 'nullable|string|max:60',
            'build'     => 'nullable|string|max:60',
            'patch'     => 'nullable|string|max:60',
            'root_path' => 'nullable|string|max:500',
            'port'      => 'nullable|integer',
            'notes'     => 'nullable|string|max:2000',
        ]);
        $a = EnvAppserver::create(array_merge($data, ['environment_id' => $env->id, 'created_by' => $request->user()->id]));
        EnvAccessLog::record($request, 'appserver_create', ['environment_id' => $env->id, 'item_label' => $a->name]);

        return response()->json(['id' => $a->id], 201);
    }

    public function updateAppserver(Request $request, int $id): JsonResponse
    {
        $this->guardInternal($request);
        $a = EnvAppserver::findOrFail($id);
        $env = $this->envWithMembership($request, $a->environment_id, 'write');
        $data = $request->validate([
            'name'      => 'sometimes|string|max:120',
            'version'   => 'nullable|string|max:60',
            'build'     => 'nullable|string|max:60',
            'patch'     => 'nullable|string|max:60',
            'root_path' => 'nullable|string|max:500',
            'port'      => 'nullable|integer',
            'notes'     => 'nullable|string|max:2000',
        ]);
        $a->update($data);
        EnvAccessLog::record($request, 'appserver_update', ['environment_id' => $env->id, 'item_label' => $a->name]);

        return response()->json(['updated' => true]);
    }

    public function destroyAppserver(Request $request, int $id): JsonResponse
    {
        $this->guardInternal($request);
        $a = EnvAppserver::findOrFail($id);
        $env = $this->envWithMembership($request, $a->environment_id, 'write');
        EnvAccessLog::record($request, 'appserver_delete', ['environment_id' => $env->id, 'item_label' => $a->name]);
        $a->delete();

        return response()->json(['deleted' => true]);
    }

    // ── VPN ───────────────────────────────────────────────────────────────────

    public function vpns(Request $request, int $envId): JsonResponse
    {
        $this->guardInternal($request);
        $env = $this->envWithMembership($request, $envId);

        $rows = EnvVpn::where('environment_id', $env->id)->orderBy('provider')->get()->map(fn ($v) => [
            'id'          => $v->id,
            'provider'    => $v->provider,
            'server'      => $v->server,
            'port'        => $v->port,
            'group'       => $v->group,
            'username'    => $v->username,
            'has_password' => $v->password_secret_id !== null,
            'secret_id'   => $v->password_secret_id,
            'critical'    => $v->critical,
            'notes'       => $v->notes,
        ]);

        return response()->json($rows);
    }

    public function storeVpn(Request $request, int $envId): JsonResponse
    {
        $this->guardInternal($request);
        $env = $this->envWithMembership($request, $envId, 'write');
        $data = $request->validate([
            'provider'      => 'sometimes|string|max:30',
            'server'        => 'nullable|string|max:200',
            'port'          => 'nullable|integer',
            'group'         => 'nullable|string|max:120',
            'username'      => 'nullable|string|max:120',
            'password_data' => 'nullable|string|max:102400',
            'critical'      => 'sometimes|boolean',
            'notes'         => 'nullable|string|max:2000',
        ]);

        $v = DB::transaction(function () use ($request, $env, $data) {
            $secretId = $this->syncSecret($request, $env, null, $data['password_data'] ?? null, 'password', $data['critical'] ?? false);

            return EnvVpn::create([
                'environment_id'     => $env->id,
                'provider'           => $data['provider'] ?? 'fortinet',
                'server'             => $data['server'] ?? null,
                'port'               => $data['port'] ?? null,
                'group'              => $data['group'] ?? null,
                'username'           => $data['username'] ?? null,
                'password_secret_id' => $secretId,
                'critical'           => $data['critical'] ?? false,
                'notes'              => $data['notes'] ?? null,
                'created_by'         => $request->user()->id,
            ]);
        });
        EnvAccessLog::record($request, 'vpn_create', ['environment_id' => $env->id, 'item_label' => $v->provider . ' ' . ($v->server ?? '')]);

        return response()->json(['id' => $v->id], 201);
    }

    public function updateVpn(Request $request, int $id): JsonResponse
    {
        $this->guardInternal($request);
        $v = EnvVpn::findOrFail($id);
        $env = $this->envWithMembership($request, $v->environment_id, 'write');
        $data = $request->validate([
            'provider'      => 'sometimes|string|max:30',
            'server'        => 'nullable|string|max:200',
            'port'          => 'nullable|integer',
            'group'         => 'nullable|string|max:120',
            'username'      => 'nullable|string|max:120',
            'password_data' => 'nullable|string|max:102400',
            'critical'      => 'sometimes|boolean',
            'notes'         => 'nullable|string|max:2000',
        ]);

        DB::transaction(function () use ($request, $v, $env, $data) {
            $v->password_secret_id = $this->syncSecret($request, $env, $v->password_secret_id, $data['password_data'] ?? null, 'password', $data['critical'] ?? $v->critical);
            $v->fill(collect($data)->only(['provider', 'server', 'port', 'group', 'username', 'critical', 'notes'])->toArray());
            $v->save();
        });
        EnvAccessLog::record($request, 'vpn_update', ['environment_id' => $env->id, 'item_label' => $v->provider]);

        return response()->json(['updated' => true]);
    }

    public function destroyVpn(Request $request, int $id): JsonResponse
    {
        $this->guardInternal($request);
        $v = EnvVpn::findOrFail($id);
        $env = $this->envWithMembership($request, $v->environment_id, 'write');
        EnvAccessLog::record($request, 'vpn_delete', ['environment_id' => $env->id, 'item_label' => $v->provider]);
        if ($v->password_secret_id) {
            EnvSecret::where('id', $v->password_secret_id)->delete();
        }
        $v->delete();

        return response()->json(['deleted' => true]);
    }
}
