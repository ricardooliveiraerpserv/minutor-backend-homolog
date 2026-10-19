<?php

namespace App\Providers;

use App\Models\AiProviderConfig;
use App\Models\SystemSetting;
use App\Services\Ai\AnthropicProvider;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\OpenAiProvider;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AiServiceProvider extends ServiceProvider
{
    /**
     * Credenciais/config cadastradas na tela (Configurador → IA) SOBREPÕEM o .env
     * em runtime, antes de qualquer feature resolver o provider. Assim chatbot,
     * documentação de fonte, reuniões e OCR passam todos a usar a chave da tela,
     * sem precisar tocar em cada ponto de chamada. Tolerante a falhas: se o banco
     * não tiver chave (ou a tabela ainda não existir), mantém o .env.
     */
    public function boot(): void
    {
        try {
            foreach (AiProviderConfig::where('enabled', true)->get() as $p) {
                if (! in_array($p->slug, ['anthropic', 'openai'], true)) {
                    continue;
                }
                $key = $p->decryptedKey();
                if ($key) {
                    config(["services.{$p->slug}.api_key" => $key]);
                }
                if (! empty($p->default_model)) {
                    config(["services.{$p->slug}.model" => $p->default_model]);
                }
                if (! empty($p->base_url)) {
                    config(["services.{$p->slug}.base_url" => $p->base_url]);
                }
            }
            $default = SystemSetting::get('ai.default_provider');
            if ($default) {
                config(['services.ai.default_provider' => $default]);
            }
        } catch (\Throwable $e) {
            // Banco indisponível / tabela ainda não migrada → segue com o .env.
        }
    }

    public function register(): void
    {
        $this->app->singleton(AiProvider::class, function () {
            $driver = config('services.ai.default_provider', 'anthropic');

            return match ($driver) {
                'anthropic' => new AnthropicProvider(
                    apiKey: (string) config('services.anthropic.api_key'),
                    model: (string) config('services.anthropic.model'),
                    baseUrl: (string) config('services.anthropic.base_url'),
                ),
                'openai' => new OpenAiProvider(
                    apiKey: (string) config('services.openai.api_key'),
                    model: (string) config('services.openai.model'),
                    baseUrl: (string) config('services.openai.base_url'),
                ),
                default => throw new RuntimeException("AI provider desconhecido: {$driver}"),
            };
        });

        // AnthropicProvider acessado diretamente (tool-use no BotQueryService)
        $this->app->singleton(AnthropicProvider::class, fn () => new AnthropicProvider(
            apiKey: (string) config('services.anthropic.api_key'),
            model: (string) config('services.anthropic.model'),
            baseUrl: (string) config('services.anthropic.base_url'),
            timeoutSeconds: 90,
        ));
    }
}
