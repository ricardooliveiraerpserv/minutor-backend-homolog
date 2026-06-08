<?php

namespace App\Workflows;

use App\Models\CardEnvolvido;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Resolve cada AUDIÊNCIA (papel) de um workflow em uma lista de e-mails, a
 * partir do contexto passado pelo ponto de envio.
 *
 * Contexto (array, chaves opcionais):
 *   contract, project, customer, request, contribution, actor (User),
 *   card => ['type' => 'contract_request'|'project', 'id' => int],
 *   consultant (User), partner (User), followup (model com ->responsible),
 *   is_child (bool) — aporte em subprojeto: cliente sai do To.
 */
class AudienceResolver
{
    /** @return array<int, string> e-mails da audiência (pode ser vazio) */
    public function emails(string $audience, array $ctx): array
    {
        $list = match ($audience) {
            'cliente'              => $this->cliente($ctx),
            'contatos_do_contrato' => $this->contatosContrato($ctx),
            'executivo_de_contas'  => $this->executivo($ctx),
            'coordenador'          => $this->coordenador($ctx),
            'administrativo'       => $this->administrativo(),
            'diretor'              => $this->diretor(),
            'envolvidos_do_card'   => $this->envolvidos($ctx),
            'watchers'             => $this->watchers($ctx),
            'autor'                => $this->autor($ctx),
            'consultor'            => $this->oneEmail($ctx['consultant'] ?? null),
            'parceiro'             => $this->oneEmail($ctx['partner'] ?? null),
            'responsavel'          => $this->oneEmail(optional($ctx['followup'] ?? null)->responsible),
            'financeiro'           => array_filter([config('workflows.financeiro_email')]),
            default                => [],
        };

        return collect($list)->filter()->map(fn ($e) => strtolower(trim($e)))->unique()->values()->all();
    }

    // ───────────────────────── papéis ─────────────────────────

    private function cliente(array $ctx): array
    {
        // Aporte em subprojeto (contrato filho): cliente nunca entra.
        if (!empty($ctx['is_child'])) {
            return [];
        }
        $customerId = $this->customerId($ctx);
        if (!$customerId) {
            return [];
        }
        return User::query()
            ->where('type', 'cliente')
            ->where('customer_id', $customerId)
            ->where('enabled', true)
            ->pluck('email')->all();
    }

    private function contatosContrato(array $ctx): array
    {
        $contract = $this->contract($ctx);
        if (!$contract) {
            return [];
        }
        $contract->loadMissing('contacts');
        return $contract->contacts->pluck('email')->all();
    }

    private function executivo(array $ctx): array
    {
        $contract = $this->contract($ctx);
        $execId = $contract->executivo_conta_id ?? null;
        if (!$execId) {
            $customerId = $this->customerId($ctx);
            $execId = $customerId ? optional(Customer::find($customerId))->executive_id : null;
        }
        return $this->userEmails([$execId]);
    }

    private function coordenador(array $ctx): array
    {
        $project = $ctx['project'] ?? null;
        if (!$project) {
            return [];
        }
        $project->loadMissing('coordinators');
        return $project->coordinators
            ->where('enabled', true)
            ->pluck('email')->all();
    }

    private function administrativo(): array
    {
        return User::query()->where('type', 'administrativo')->where('enabled', true)->pluck('email')->all();
    }

    private function diretor(): array
    {
        $email = config('workflows.diretor_email');
        if (!$email) {
            return [];
        }
        // Só envia se houver usuário ativo com esse e-mail (mesma regra do legado).
        $exists = User::query()->where('email', $email)->where('enabled', true)->exists();
        return $exists ? [$email] : [];
    }

    private function envolvidos(array $ctx): array
    {
        $card = $ctx['card'] ?? null;
        if (!$card || empty($card['type']) || empty($card['id'])) {
            return [];
        }
        $actorId = optional($ctx['actor'] ?? null)->id;
        return CardEnvolvido::query()
            ->where('card_type', $card['type'])
            ->where('card_id', $card['id'])
            ->get()
            ->reject(fn ($e) => $actorId && $e->user_id === $actorId)
            ->map(fn ($e) => $e->notification_email)
            ->all();
    }

    private function watchers(array $ctx): array
    {
        $request = $ctx['request'] ?? null;
        if (!$request) {
            return [];
        }
        $request->loadMissing('watchers');
        return $request->watchers->pluck('email')->all();
    }

    private function autor(array $ctx): array
    {
        return $this->oneEmail($ctx['actor'] ?? null);
    }

    // ───────────────────────── helpers ─────────────────────────

    private function oneEmail($user): array
    {
        $email = is_object($user) ? ($user->email ?? null) : null;
        return $email ? [$email] : [];
    }

    private function userEmails(array $ids): array
    {
        $ids = array_values(array_filter($ids));
        if (empty($ids)) {
            return [];
        }
        return User::query()->whereIn('id', $ids)->where('enabled', true)->pluck('email')->all();
    }

    private function contract(array $ctx)
    {
        if (!empty($ctx['contract'])) {
            return $ctx['contract'];
        }
        if (!empty($ctx['project'])) {
            $ctx['project']->loadMissing('contract');
            return $ctx['project']->contract;
        }
        if (!empty($ctx['request'])) {
            $ctx['request']->loadMissing('linkedContract');
            return $ctx['request']->linkedContract;
        }
        return null;
    }

    private function customerId(array $ctx): ?int
    {
        if (!empty($ctx['customer'])) {
            return (int) (is_object($ctx['customer']) ? $ctx['customer']->id : $ctx['customer']);
        }
        foreach (['project', 'contract', 'request'] as $k) {
            if (!empty($ctx[$k]) && isset($ctx[$k]->customer_id) && $ctx[$k]->customer_id) {
                return (int) $ctx[$k]->customer_id;
            }
        }
        return null;
    }
}
