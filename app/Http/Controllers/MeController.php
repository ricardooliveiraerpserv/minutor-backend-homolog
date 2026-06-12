<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    /**
     * Comentários onde o usuário corrente foi mencionado via `@[id:Name]`.
     * Agrega 4 fontes em uma lista única ordenada por data:
     *  - stage_activity_events (timeline operacional de atividade — payload->mentioned_user_ids)
     *  - project_message_mentions (chat de projeto)
     *  - contract_request_message_mentions (chat de requisição)
     *  - contract_message_mentions (chat de contrato)
     *
     * Cada item carrega contexto pra deep link no FE (project_id, request_id, contract_id, delivery_id).
     */
    public function mentions(Request $request): JsonResponse
    {
        $user = $request->user();
        $items = collect();

        // Cliente NUNCA vê chat de projeto (regra cards) — pula 2 fontes ligadas a projeto
        $isClient = $user->isCliente();

        // (fonte 'activity' / stage_activity_events fica fora de prod — Cronograma não está em produção)

        // 2) Chat de projeto — cliente nunca recebe
        if (!$isClient) {
        $projectMentions = \App\Models\ProjectMessageMention::query()
            ->where('mentioned_user_id', $user->id)
            ->with([
                'message:id,project_id,user_id,message,created_at',
                'message.author:id,name',
                'message.project:id,name',
            ])
            ->whereHas('message')
            ->orderByDesc('id')
            ->limit(100)
            ->get();
        foreach ($projectMentions as $m) {
            $items->push([
                'source'     => 'project_chat',
                'id'         => "pm-{$m->id}",
                'created_at' => $m->message?->created_at?->toIso8601String(),
                'actor'      => $m->message?->author ? ['id' => $m->message->author->id, 'name' => $m->message->author->name] : null,
                'text'       => $m->message?->message,
                'project_id' => $m->message?->project?->id,
                'project'    => $m->message?->project?->name,
            ]);
        }
        } // close non-client guard

        // 3) Chat de requisição — cliente vê SÓ até req_decided_at (regra ADR cards)
        $reqMentionsQuery = \App\Models\ContractRequestMessageMention::query()
            ->where('mentioned_user_id', $user->id)
            ->with([
                'message:id,contract_request_id,user_id,message,created_at',
                'message.author:id,name',
                'message.request:id,customer_id,req_decided_at,project_name,linked_project_id',
                'message.request.customer:id,name',
            ])
            ->whereHas('message');

        if ($isClient) {
            $reqMentionsQuery->whereHas('message.request', function ($q) {
                $q->whereNull('req_decided_at');
            });
        }

        $reqMentions = $reqMentionsQuery->orderByDesc('id')->limit(100)->get();
        foreach ($reqMentions as $m) {
            $req = $m->message?->request;
            $items->push([
                'source'      => 'request_chat',
                'id'          => "rm-{$m->id}",
                'created_at'  => $m->message?->created_at?->toIso8601String(),
                'actor'       => $m->message?->author ? ['id' => $m->message->author->id, 'name' => $m->message->author->name] : null,
                'text'        => $m->message?->message,
                'request_id'  => $m->message?->contract_request_id,
                'customer'    => $req?->customer?->name,
                'project_name' => $req?->project_name, // texto livre da requisição
            ]);
        }

        // 4) Chat de contrato — cliente NUNCA recebe (não tem acesso ao chat de contrato)
        $contractMentions = collect();
        if (!$isClient) {
            $contractMentions = \App\Models\ContractMessageMention::query()
                ->where('mentioned_user_id', $user->id)
                ->with([
                    'message:id,contract_id,user_id,message,visibility,created_at',
                    'message.author:id,name',
                    'message.contract:id,customer_id,project_name',
                    'message.contract.customer:id,name',
                ])
                ->whereHas('message')
                ->orderByDesc('id')
                ->limit(100)
                ->get();
        }
        foreach ($contractMentions as $m) {
            $items->push([
                'source'      => 'contract_chat',
                'id'          => "cm-{$m->id}",
                'created_at'  => $m->message?->created_at?->toIso8601String(),
                'actor'       => $m->message?->author ? ['id' => $m->message->author->id, 'name' => $m->message->author->name] : null,
                'text'        => $m->message?->message,
                'contract_id' => $m->message?->contract_id,
                'customer'    => $m->message?->contract?->customer?->name,
                'project_name' => $m->message?->contract?->project_name,
            ]);
        }

        // Ordena tudo por created_at desc, limit 100 (FE separa não-lidas + 10 lidas)
        $final = $items
            ->sortByDesc(fn ($it) => $it['created_at'] ?? '')
            ->take(100)
            ->values();

        return response()->json([
            'items' => $final,
            'count' => $final->count(),
        ]);
    }

    /**
     * Customer vinculado ao usuário corrente. Substitui /customers/{id} pro
     * header do cliente, que não tem permissão `customers.view`.
     * Retorna 204 quando o usuário não tem customer_id.
     */
    public function customer(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user->customer_id) {
            return response()->json(null);
        }

        $customer = Customer::query()
            ->where('id', $user->customer_id)
            ->first(['id', 'name']);

        return response()->json($customer);
    }
}
