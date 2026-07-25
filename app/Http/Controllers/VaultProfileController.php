<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Vault;
use App\Models\VaultAccessLog;
use App\Models\VaultMember;
use App\Models\VaultUserKey;
use App\Services\MicrosoftCalendarService;
use App\Services\Totp;
use App\Services\VaultStepUp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Cofre de Senhas — perfil criptográfico, TOTP e unlock (zero-knowledge).
 *
 * O servidor NUNCA vê master password nem chaves em claro: recebe/entrega apenas
 * blobs cifrados no client (TEXT opaco) + o auth_hash derivado (guardado como bcrypt).
 * "Destravar" é estado do CLIENT — aqui só se valida auth_hash+TOTP e devolvem-se blobs.
 * NUNCA logar payloads destas rotas.
 */
class VaultProfileController extends Controller
{
    private const INTERNAL_TYPES = ['admin', 'administrativo', 'coordenador', 'consultor'];

    /** Cinto-e-suspensório além do permission.or.admin:vault.use — cliente/parceiro NUNCA. */
    private function guardInternal(Request $request): User
    {
        $user = $request->user();
        abort_unless(in_array($user->effectiveType(), self::INTERNAL_TYPES, true), 403);

        return $user;
    }

    private function keysFor(User $user): VaultUserKey
    {
        return VaultUserKey::firstOrCreate(['user_id' => $user->id]);
    }

    /** Valida um código TOTP confirmado do usuário e grava o timestep (anti-replay). */
    private function checkTotp(VaultUserKey $keys, ?string $code): bool
    {
        if (! $keys->totpConfirmed() || ! $keys->totp_secret || ! $code) {
            return false;
        }
        $timestep = Totp::verify($keys->totp_secret, $code, $keys->totp_last_timestep);
        if ($timestep === null) {
            return false;
        }
        $keys->forceFill(['totp_last_timestep' => $timestep])->save();

        return true;
    }

    /** 2º fator conforme o driver: Microsoft (stepup_token) ou TOTP (totp_code). */
    private function checkSecondFactor(VaultUserKey $keys, Request $request): bool
    {
        if (VaultStepUp::driver() === 'microsoft') {
            return VaultStepUp::check($keys, (string) $request->input('stepup_token'));
        }

        return $this->checkTotp($keys, (string) $request->input('totp_code'));
    }

    /** 2º fator pronto pro setup? Microsoft: conta pinada · TOTP: confirmado no app. */
    private function secondFactorReady(VaultUserKey $keys): bool
    {
        return VaultStepUp::driver() === 'microsoft'
            ? ! empty($keys->ms_oid)
            : $keys->totpConfirmed();
    }

    public function profile(Request $request): JsonResponse
    {
        $user = $this->guardInternal($request);
        $keys = VaultUserKey::where('user_id', $user->id)->first();

        return response()->json([
            'configured'     => (bool) $keys?->isConfigured(),
            'second_factor'  => VaultStepUp::driver(),
            'totp_confirmed' => (bool) $keys?->totpConfirmed(),
            'ms_linked'      => ! empty($keys?->ms_oid),
            'has_recovery'   => ! empty($keys?->recovery_symmetric_key),
            'kdf'            => [
                'iterations'  => $keys?->kdf_iterations ?? 3,
                'memory'      => $keys?->kdf_memory ?? 65536,
                'parallelism' => $keys?->kdf_parallelism ?? 4,
            ],
        ]);
    }

    /**
     * Inicia o step-up Microsoft: devolve a authorize URL p/ o popup.
     * prompt=login FORÇA re-autenticação (com a MFA corporativa) a cada unlock.
     */
    public function msStart(Request $request): JsonResponse
    {
        $this->guardInternal($request);
        if (VaultStepUp::driver() !== 'microsoft' || ! MicrosoftCalendarService::configured()) {
            return response()->json(['message' => 'Verificação Microsoft indisponível.'], 422);
        }

        $state = Crypt::encryptString(json_encode([
            'uid'  => $request->user()->id,
            't'    => now()->timestamp,
            'flow' => 'vault',
        ]));

        // Mantém os scopes padrão (exchangeCode reenvia os mesmos na troca do code);
        // só força prompt=login pra re-autenticação fresca.
        return response()->json(['authorize_url' => MicrosoftCalendarService::authorizeUrl($state, [
            'prompt' => 'login',
        ])]);
    }

    public function totpSetup(Request $request): JsonResponse
    {
        $user = $this->guardInternal($request);
        $keys = $this->keysFor($user);

        // Re-setup com TOTP já ativo exige step-up do secret ATUAL
        if ($keys->totpConfirmed() && ! $this->checkTotp($keys, (string) $request->input('totp_code'))) {
            return response()->json(['message' => 'Código do autenticador atual necessário para reconfigurar.'], 422);
        }

        $secret = Totp::generateSecret();
        $keys->forceFill([
            'totp_secret'        => $secret,
            'totp_confirmed_at'  => null,
            'totp_last_timestep' => null,
        ])->save();

        return response()->json([
            'otpauth_uri'   => Totp::otpauthUri($secret, $user->email),
            'secret_base32' => $secret,
        ]);
    }

    public function totpConfirm(Request $request): JsonResponse
    {
        $user = $this->guardInternal($request);
        $data = $request->validate(['code' => 'required|string|max:10']);
        $keys = $this->keysFor($user);

        if (! $keys->totp_secret) {
            return response()->json(['message' => 'Nenhum setup de autenticador pendente.'], 422);
        }
        $timestep = Totp::verify($keys->totp_secret, $data['code'], $keys->totp_last_timestep);
        if ($timestep === null) {
            return response()->json(['message' => 'Código inválido.'], 422);
        }
        $keys->forceFill(['totp_confirmed_at' => now(), 'totp_last_timestep' => $timestep])->save();

        return response()->json(['confirmed' => true]);
    }

    public function setup(Request $request): JsonResponse
    {
        $user = $this->guardInternal($request);
        $data = $request->validate([
            'auth_hash'                          => 'required|string|max:500',
            'encrypted_symmetric_key'            => 'required|string|max:2000',
            'public_key'                         => 'required|string|max:5000',
            'encrypted_private_key'              => 'required|string|max:10000',
            'recovery_symmetric_key'             => 'required|string|max:2000',
            'kdf_iterations'                     => 'required|integer|min:2|max:10',
            'kdf_memory'                         => 'required|integer|min:19456|max:1048576',
            'kdf_parallelism'                    => 'required|integer|min:1|max:16',
            'personal_vault.encrypted_vault_key' => 'required|string|max:2000',
        ]);

        $keys = $this->keysFor($user);
        if ($keys->isConfigured()) {
            return response()->json(['message' => 'Cofre já configurado.'], 409);
        }
        if (! $this->secondFactorReady($keys)) {
            return response()->json(['message' => 'Configure o 2º fator (Microsoft ou autenticador) antes de concluir.'], 422);
        }
        // Driver Microsoft: exige step-up fresco no próprio setup
        if (VaultStepUp::driver() === 'microsoft' && ! VaultStepUp::check($keys, (string) $request->input('stepup_token'))) {
            return response()->json(['message' => 'Verificação Microsoft expirada — repita a verificação.'], 422);
        }

        DB::transaction(function () use ($user, $keys, $data) {
            $keys->forceFill([
                'auth_hash'               => Hash::make($data['auth_hash']),
                'encrypted_symmetric_key' => $data['encrypted_symmetric_key'],
                'public_key'              => $data['public_key'],
                'encrypted_private_key'   => $data['encrypted_private_key'],
                'recovery_symmetric_key'  => $data['recovery_symmetric_key'],
                'kdf_iterations'          => $data['kdf_iterations'],
                'kdf_memory'              => $data['kdf_memory'],
                'kdf_parallelism'         => $data['kdf_parallelism'],
            ])->save();

            $vault = Vault::firstOrCreate(
                ['type' => 'personal', 'created_by' => $user->id],
                ['name' => 'Cofre Pessoal']
            );
            VaultMember::updateOrCreate(
                ['vault_id' => $vault->id, 'user_id' => $user->id],
                // ?? 1: model recém-criado por firstOrCreate não carrega o default do banco
                ['role' => 'admin', 'encrypted_vault_key' => $data['personal_vault']['encrypted_vault_key'], 'key_version' => $vault->key_version ?? 1]
            );
        });

        VaultAccessLog::record($request, 'profile_setup');

        return response()->json(['configured' => true], 201);
    }

    /**
     * Unlock: auth_hash + TOTP válidos => devolve os blobs cifrados.
     * Erro é sempre GENÉRICO (não revelar se falhou hash ou TOTP).
     */
    public function unlock(Request $request): JsonResponse
    {
        $user = $this->guardInternal($request);
        $data = $request->validate([
            'auth_hash'    => 'required|string|max:500',
            'totp_code'    => 'nullable|string|max:10',
            'stepup_token' => 'nullable|string|max:500',
        ]);

        $keys = VaultUserKey::where('user_id', $user->id)->first();
        $hashOk = $keys?->isConfigured() && Hash::check($data['auth_hash'], $keys->auth_hash);
        $totpOk = $keys && $this->checkSecondFactor($keys, $request);

        if (! $hashOk || ! $totpOk) {
            VaultAccessLog::record($request, 'unlock_failed');

            return response()->json(['message' => 'Credenciais do cofre inválidas.'], 422);
        }

        VaultAccessLog::record($request, 'unlock_success');

        return response()->json([
            'encrypted_symmetric_key' => $keys->encrypted_symmetric_key,
            'encrypted_private_key'   => $keys->encrypted_private_key,
            'public_key'              => $keys->public_key,
            'kdf'                     => [
                'iterations'  => $keys->kdf_iterations,
                'memory'      => $keys->kdf_memory,
                'parallelism' => $keys->kdf_parallelism,
            ],
        ]);
    }

    /**
     * Troca de master password: só RE-WRAP da user symmetric key (itens intocados).
     * Step-up: (auth_hash atual + TOTP fresco) OU token efêmero do fluxo de recovery
     * (emitido pelo recoveryUnlock, que já validou TOTP — cada código vale uma vez).
     */
    public function changeMasterPassword(Request $request): JsonResponse
    {
        $user = $this->guardInternal($request);
        $data = $request->validate([
            'current_auth_hash'           => 'nullable|string|max:500',
            'recovery_token'              => 'nullable|string|max:500',
            'totp_code'                   => 'nullable|string|max:10',
            'stepup_token'                => 'nullable|string|max:500',
            'new_auth_hash'               => 'required|string|max:500',
            'new_encrypted_symmetric_key' => 'required|string|max:2000',
            'new_recovery_symmetric_key'  => 'nullable|string|max:2000',
        ]);

        $keys = VaultUserKey::where('user_id', $user->id)->first();
        if (! $keys?->isConfigured()) {
            return response()->json(['message' => 'Cofre não configurado.'], 422);
        }

        $viaRecovery = ! empty($data['recovery_token']);
        if ($viaRecovery) {
            $tokenOk = $keys->recovery_token_hash
                && $keys->recovery_token_expires_at?->isFuture()
                && Hash::check($data['recovery_token'], $keys->recovery_token_hash);
            if (! $tokenOk) {
                return response()->json(['message' => 'Sessão de recuperação expirada — reinicie o processo.'], 422);
            }
        } else {
            if (! $this->checkSecondFactor($keys, $request)) {
                return response()->json(['message' => 'Verificação de 2º fator inválida.'], 422);
            }
            if (! Hash::check((string) ($data['current_auth_hash'] ?? ''), $keys->auth_hash)) {
                return response()->json(['message' => 'Master password atual inválida.'], 422);
            }
        }
        // Via recovery, o novo blob só é válido se o client decifrou a userSymKey com a
        // recovery key real — sem ela o unlock passa a falhar, nada é exposto.

        $keys->forceFill([
            'auth_hash'                 => Hash::make($data['new_auth_hash']),
            'encrypted_symmetric_key'   => $data['new_encrypted_symmetric_key'],
            'recovery_symmetric_key'    => $data['new_recovery_symmetric_key'] ?? $keys->recovery_symmetric_key,
            'recovery_token_hash'       => null,
            'recovery_token_expires_at' => null,
        ])->save();

        VaultAccessLog::record($request, $viaRecovery ? 'recovery_used' : 'master_password_change');

        return response()->json(['changed' => true]);
    }

    /**
     * Recovery: TOTP + posse da recovery key física (o blob só abre com ela).
     * Devolve também um TOKEN efêmero (10 min, uso único) que autoriza o
     * POST master-password com recovery:true — evita consumir dois códigos TOTP.
     */
    public function recoveryUnlock(Request $request): JsonResponse
    {
        $user = $this->guardInternal($request);
        $request->validate([
            'totp_code'    => 'nullable|string|max:10',
            'stepup_token' => 'nullable|string|max:500',
        ]);

        $keys = VaultUserKey::where('user_id', $user->id)->first();
        if (! $keys?->recovery_symmetric_key || ! $this->checkSecondFactor($keys, $request)) {
            return response()->json(['message' => 'Não foi possível iniciar a recuperação.'], 422);
        }

        $token = base64_encode(random_bytes(32));
        $keys->forceFill([
            'recovery_token_hash'       => Hash::make($token),
            'recovery_token_expires_at' => now()->addMinutes(10),
        ])->save();

        VaultAccessLog::record($request, 'recovery_used', [], ['stage' => 'blob_delivered']);

        return response()->json([
            'recovery_symmetric_key' => $keys->recovery_symmetric_key,
            'recovery_token'         => $token,
        ]);
    }

    /** Chaves públicas de usuários internos com perfil configurado (p/ compartilhar cofres). */
    public function publicKeys(Request $request): JsonResponse
    {
        $this->guardInternal($request);
        $ids = collect(explode(',', (string) $request->query('user_ids')))
            ->filter(fn ($v) => ctype_digit(trim($v)))->map(fn ($v) => (int) $v)->values();

        $query = VaultUserKey::query()
            ->whereNotNull('public_key')
            ->whereNotNull('auth_hash')
            ->join('users', 'users.id', '=', 'vault_user_keys.user_id')
            ->whereIn('users.type', self::INTERNAL_TYPES)
            ->where('users.enabled', true)
            ->select('vault_user_keys.user_id', 'users.name', 'users.email', 'vault_user_keys.public_key');
        if ($ids->isNotEmpty()) {
            $query->whereIn('vault_user_keys.user_id', $ids);
        }

        return response()->json($query->orderBy('users.name')->get());
    }
}
