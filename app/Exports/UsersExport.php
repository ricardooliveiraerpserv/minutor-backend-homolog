<?php

namespace App\Exports;

use App\Models\User;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Export de USUÁRIOS em Excel, uma ABA por categoria (um consultor é sempre interno,
 * parceiro ou freelance — não há aba "Consultor" à parte):
 *  - Interno   : equipe ERPSERV (admin/coordenador/administrativo) + consultor com vínculo
 *                'fixo' ou sem vínculo (empregados)
 *  - Freelance : type=consultor com vínculo 'freelance'
 *  - Parceiro  : type=parceiro_admin
 *  - Cliente   : type=cliente
 * Cada aba traz TODOS os campos do usuário.
 */
class UsersExport implements WithMultipleSheets
{
    /** @param Collection<int,User> $users */
    public function __construct(protected Collection $users) {}

    public function sheets(): array
    {
        // Interno = equipe ERPSERV + consultor NÃO-freelance (vínculo fixo/sem vínculo = empregado).
        $interno   = $this->users->filter(fn ($u) => in_array($u->type, ['admin', 'coordenador', 'administrativo'], true)
            || ($u->type === 'consultor' && $u->work_bond !== 'freelance'));
        $freelance = $this->users->filter(fn ($u) => $u->type === 'consultor' && $u->work_bond === 'freelance');
        $parceiro  = $this->users->filter(fn ($u) => $u->type === 'parceiro_admin');
        $cliente   = $this->users->filter(fn ($u) => $u->type === 'cliente');

        return [
            new UsersSheet('Interno',   $interno->values()),
            new UsersSheet('Freelance', $freelance->values()),
            new UsersSheet('Parceiro',  $parceiro->values()),
            new UsersSheet('Cliente',   $cliente->values()),
        ];
    }
}
