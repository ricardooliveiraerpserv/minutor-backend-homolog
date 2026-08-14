<?php

namespace App\SourceCode\Analyzer;

/**
 * Abstração do provedor de IA da documentação — para trocar Anthropic por outro provider ou
 * modelo privado sem reescrever o motor de documentação. É um cliente LLM fino: recebe system +
 * user (JÁ sanitizados pelo chamador) e devolve o texto. NUNCA loga o payload integral.
 */
interface SourceDocAiProvider
{
    public function isConfigured(): bool;

    public function name(): string;

    public function model(): string;

    /**
     * Completa um prompt. Retorna ['text'=>string, 'usage'=>array, 'stop'=>?string].
     * Lança RuntimeException (mensagem SEM segredo/payload) em falha.
     * @param array{max_tokens?:int,temperature?:float,model?:string} $opts
     */
    public function complete(string $system, string $user, array $opts = []): array;
}
