<?php

namespace App\Services\Ai;

use App\Enums\MessageType;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Ai\Tools\MinutorToolRegistry;
use App\Services\Inbox\InboxService;
use Illuminate\Support\Facades\Log;

/**
 * BotQueryService — invocado quando o usuário menciona @bot em uma conversa.
 * Persiste a mensagem do user, chama IA com tool-use, executa as tools
 * iterativamente até a IA dar a resposta final, e posta a resposta no chat.
 */
class BotQueryService
{
    public const MAX_ITERATIONS = 5;

    public function __construct(
        protected AnthropicProvider $ai,
        protected MinutorToolRegistry $tools,
        protected InboxService $inbox,
    ) {
    }

    /**
     * @return array{user_message:Message, bot_message:Message, tools_called:array<string>}
     */
    public function ask(User $user, Conversation $conversation, string $question): array
    {
        // 1) Persiste mensagem do user (texto integral, com o @bot)
        $userMsg = $this->inbox->persist($conversation, [
            'sender_user_id' => $user->id,
            'type'           => MessageType::User,
            'body'           => $question,
            'metadata'       => ['mentions_bot' => true],
        ]);

        // 2) Posta placeholder do BOT (substituído ao fim)
        $placeholder = $this->inbox->persist($conversation, [
            'sender_user_id' => null,
            'type'           => MessageType::Bot,
            'body'           => '🔍 Consultando o Minutor…',
            'metadata'       => ['pending' => true, 'user_message_id' => $userMsg->id],
        ]);

        try {
            [$reply, $toolsCalled] = $this->runToolLoop($user, $question);
        } catch (\Throwable $e) {
            Log::error('[BotQueryService] erro no tool loop', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
            $reply = "❌ Erro ao consultar: " . $e->getMessage();
            $toolsCalled = [];
        }

        // 3) Atualiza o placeholder com a resposta final
        $placeholder->update([
            'body'     => $reply,
            'metadata' => [
                'pending'         => false,
                'user_message_id' => $userMsg->id,
                'tools_called'    => $toolsCalled,
                'provider'        => $this->ai->name(),
            ],
        ]);

        return [
            'user_message' => $userMsg->refresh(),
            'bot_message'  => $placeholder->refresh(),
            'tools_called' => $toolsCalled,
        ];
    }

    /**
     * Loop iterativo: chama IA, executa tools, devolve resultados, repete até end_turn.
     * @return array{0:string,1:array<string>}  [textoFinal, listaToolsChamadas]
     */
    protected function runToolLoop(User $user, string $question): array
    {
        $system = $this->systemPrompt($user);
        $allowedScopes = $this->computeAllowedScopes($user);
        $tools = $this->tools->definitions($allowedScopes);
        $messages = [
            ['role' => 'user', 'content' => $question],
        ];

        $toolsCalled = [];
        $finalText = '';

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            $resp = $this->ai->callWithTools($system, $messages, $tools, ['max_tokens' => 2048]);
            $stop = $resp['stop_reason'] ?? null;
            $content = $resp['content'] ?? [];

            // Coleta texto desta resposta
            foreach ($content as $b) {
                if (($b['type'] ?? null) === 'text') {
                    $finalText = trim($b['text'] ?? '');
                }
            }

            if ($stop !== 'tool_use') {
                // Resposta final (end_turn ou max_tokens)
                return [$finalText, $toolsCalled];
            }

            // Adiciona resposta do assistant com os tool_use blocks
            $messages[] = ['role' => 'assistant', 'content' => $content];

            // Executa cada tool_use e devolve tool_result
            $toolResults = [];
            foreach ($content as $b) {
                if (($b['type'] ?? null) !== 'tool_use') continue;
                $name = $b['name'] ?? '';
                $input = $b['input'] ?? [];
                $toolsCalled[] = $name;

                $result = $this->tools->execute($name, $input, $allowedScopes, $user);
                $toolResults[] = [
                    'type'        => 'tool_result',
                    'tool_use_id' => $b['id'] ?? '',
                    'content'     => json_encode($result, JSON_UNESCAPED_UNICODE),
                ];
            }
            $messages[] = ['role' => 'user', 'content' => $toolResults];
        }

        return [$finalText ?: '⚠️ Limite de iterações atingido sem resposta final.', $toolsCalled];
    }

    /**
     * Determina quais scopes de tools o BOT pode invocar nesta query.
     *
     * Regra de composição:
     *  1) União dos `allowed_scopes` de todos os agents ativos (= scopes liberados pelo sistema).
     *     - Se algum agent tem allowed_scopes null → SISTEMA libera todos.
     *  2) Intersecção com `bot_allowed_scopes` do USUÁRIO autenticado.
     *     - Se user.bot_allowed_scopes é null → sem restrição extra (mantém scopes do sistema).
     *
     * Retorno:
     *  - null  → liberar TUDO (sem restrição)
     *  - array → lista de scopes habilitados (pode estar vazia → BOT não tem tools disponíveis)
     */
    protected function computeAllowedScopes(User $user): ?array
    {
        // 1) Sistema (union dos agents ativos)
        $agents = \App\Models\BotAgent::query()->where('active', true)->get(['allowed_scopes']);
        $systemUnrestricted = false;
        $systemScopes = [];
        foreach ($agents as $a) {
            if (! $a->allowed_scopes) { $systemUnrestricted = true; continue; }
            foreach ($a->allowed_scopes as $s) {
                $systemScopes[$s] = true;
            }
        }
        $sistema = $systemUnrestricted || $agents->isEmpty() ? null : array_keys($systemScopes);

        // 2) Restrição do usuário
        $userScopes = $user->bot_allowed_scopes;

        if ($sistema === null && (! is_array($userScopes) || empty($userScopes))) {
            return null; // libera tudo
        }
        if ($sistema === null) {
            return array_values(array_unique($userScopes));
        }
        if (! is_array($userScopes) || empty($userScopes)) {
            return $sistema;
        }
        // Intersecção
        return array_values(array_intersect($sistema, $userScopes));
    }

    protected function systemPrompt(User $user): string
    {
        $vis = $user->bot_visibility ?? 'self';
        $visBlock = match ($vis) {
            'self' => "ESCOPO DE DADOS: {$user->name} só pode ver dados PRÓPRIOS. "
                   . "Nunca passe user_id de outro consultor; se omitir, o sistema usa o próprio user. "
                   . "Em consultas de cliente, só estão acessíveis os clientes onde {$user->name} atua. "
                   . "Se a pergunta for sobre outro consultor/cliente, recuse educadamente.",
            'team' => "ESCOPO DE DADOS: {$user->name} vê dados da própria EQUIPE "
                   . "(consultores e clientes dos projetos que coordena/lidera). "
                   . "Fora disso, o sistema bloqueia automaticamente — não force.",
            'all'  => "ESCOPO DE DADOS: {$user->name} tem visão completa (admin).",
            default => "",
        };

        return <<<SYS
        Você é o BOT Minutor, assistente operacional da ERPSERV (consultoria TOTVS Protheus).
        Atua dentro do sistema Minutor (PSA: projetos, contratos, banco de horas, sustentação).

        Usuário atual: {$user->name} (id={$user->id}, perfil={$user->type}).
        {$visBlock}

        Princípios:
        - Use APENAS as tools fornecidas para consultar dados. Não invente números.
        - Para qualquer pergunta sobre um cliente, comece por search_customer.
        - Seja conciso e operacional: bullets, números, e ação. Evite parágrafos longos.
        - Responda em português do Brasil.
        - Use unidades claras: horas (h), R\$ para valores.
        - Se uma tool devolver erro de permissão, NÃO tente contornar com outro user_id/customer_id —
          explique pro usuário que está fora do escopo dele e sugira pedir ao admin.
        - Se a pergunta exigir dado fora do escopo das tools, diga abertamente.
        - Formate resposta final em markdown (bullets, negrito em números-chave).

        Formato sugerido pra "como está o cliente X":
        **Cliente:** Nome
        - X projetos (Y ativos, Z encerrados)
        - Horas: vendidas vs consumidas vs saldo
        - Contratos: tipos + valores
        - Próxima ação sugerida (se houver risco visível)
        SYS;
    }
}
