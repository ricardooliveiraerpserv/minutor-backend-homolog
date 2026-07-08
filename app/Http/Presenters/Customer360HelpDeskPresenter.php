<?php

namespace App\Http\Presenters;

use App\Models\Customer;
use App\Models\HelpDeskTicket;
use App\Models\User;
use App\Services\Customer360Service;

/**
 * Customer 360 — visão OPERACIONAL do Help Desk.
 *
 * Consome o núcleo `Customer360Service` (mesmo modelo de dados de toda a plataforma) e
 * decide a EXPERIÊNCIA do Help Desk: quais blocos, organização e a REDAÇÃO por perfil.
 *
 * Visibilidade (diretriz): todo usuário interno vê o contexto OPERACIONAL completo; a
 * única diferença é o FINANCEIRO — consultor não vê valores (valor-hora, valor de
 * proposta/oportunidade), coordenador/administrativo/admin veem. O serviço entrega o
 * financeiro sob a chave `financeiro` de cada bloco; aqui removemos para quem não pode.
 *
 * Acesso (FORK 2): o contexto acompanha o chamado — quem pode atender o chamado
 * (help_desk.tickets.view) recebe este contexto do cliente daquele chamado.
 */
class Customer360HelpDeskPresenter
{
    public function __construct(private Customer360Service $svc)
    {
    }

    public function present(Customer $customer, ?HelpDeskTicket $ticket, User $user): array
    {
        $financeiro = $this->canSeeFinancial($user);

        $blocos = [
            'cliente'   => $this->svc->cliente($customer, $ticket),
            'contrato'  => $this->svc->contrato($customer, $ticket),
            'projetos'  => $this->svc->projetos($customer, $ticket),
            'help_desk' => $this->svc->helpDesk($customer),
            'comercial' => $this->svc->comercial($customer),
            'operacao'  => $this->svc->operacao($customer),
        ];

        // Diagnósticos (regras → estrutura "atenções") sobre os blocos COMPLETOS; troca por IA depois
        // mantém a mesma saída/UI. Calculado antes de redigir o financeiro (não expõe valores).
        $atencoes = app(\App\Services\Customer360Diagnostics::class)->analyze($blocos, $ticket);

        if (!$financeiro) {
            $blocos = $this->stripFinancial($blocos);
        }

        return [
            'financeiro_visivel' => $financeiro,
            'atencoes'           => $atencoes,
            'blocos'             => $blocos,
        ];
    }

    /** Financeiro liberado a coordenação/administração; consultor vê só operacional. */
    private function canSeeFinancial(User $user): bool
    {
        return $user->isAdmin() || $user->isCoordenador() || $user->type === 'administrativo';
    }

    /** Remove recursivamente qualquer chave `financeiro` (em qualquer profundidade). */
    private function stripFinancial(array $data): array
    {
        unset($data['financeiro']);
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = $this->stripFinancial($v);
            }
        }
        return $data;
    }
}
