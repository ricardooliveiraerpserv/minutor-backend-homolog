<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Configuração de um provedor de IA (Anthropic, OpenAI, Gemini, ...).
 *
 * A credencial pode vir de DOIS lugares, nesta ordem de precedência:
 *  1. `api_key` — chave CRIPTOGRAFADA no banco (cast `encrypted`), cadastrada pela
 *     tela Configurador → IA. Quando preenchida, é a fonte da verdade.
 *  2. `api_key_env` — NOME de uma env var (ex.: 'ANTHROPIC_API_KEY'); a chave real
 *     é lida do ambiente. Fallback quando o banco não tem chave.
 *
 * A chave real NUNCA é exposta no JSON (ver $hidden); a tela só vê `has_key`.
 */
class AiProviderConfig extends Model
{
    protected $table = 'ai_providers';

    protected $fillable = [
        'slug', 'name', 'api_key_env', 'api_key', 'base_url', 'default_model', 'enabled',
    ];

    protected $hidden = [
        'api_key',
    ];

    protected $casts = [
        'enabled'  => 'boolean',
        'api_key'  => 'encrypted', // criptografa em repouso com o APP_KEY
    ];

    /** Chave do banco (descriptografada) ou null — tolerante a APP_KEY divergente. */
    public function decryptedKey(): ?string
    {
        try {
            return ! empty($this->api_key) ? (string) $this->api_key : null;
        } catch (\Throwable $e) {
            return null; // chave ilegível (ex.: APP_KEY mudou) → cai no .env
        }
    }

    /** Credencial efetiva: banco primeiro, depois a env var nomeada. */
    public function resolveApiKey(): ?string
    {
        return $this->decryptedKey() ?: (env($this->api_key_env) ?: null);
    }

    public function hasKey(): bool
    {
        return ! empty($this->resolveApiKey());
    }

    /** De onde a chave efetiva vem: 'db' (cadastrada na tela), 'env' (.env) ou 'none'. */
    public function keySource(): string
    {
        if ($this->decryptedKey()) {
            return 'db';
        }
        return (env($this->api_key_env) ? 'env' : 'none');
    }
}
