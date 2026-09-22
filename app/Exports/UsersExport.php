<?php

namespace App\Exports;

use App\Models\User;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Export de USUÁRIOS em Excel, uma ABA por categoria:
 *  - Consultor : type=consultor com vínculo 'fixo' (ou sem vínculo)
 *  - Freelance : type=consultor com vínculo 'freelance'
 *  - Parceiro  : type=parceiro_admin
 *  - Interno   : equipe ERPSERV (admin / coordenador / administrativo)
 *  - Cliente   : type=cliente
 * Cada aba traz TODOS os campos do usuário.
 */
class UsersExport implements WithMultipleSheets
{
    /** @param Collection<int,User> $users */
    public function __construct(protected Collection $users) {}

    public function sheets(): array
    {
        $consultor = $this->users->filter(fn ($u) => $u->type === 'consultor' && $u->work_bond !== 'freelance');
        $freelance = $this->users->filter(fn ($u) => $u->type === 'consultor' && $u->work_bond === 'freelance');
        $parceiro  = $this->users->filter(fn ($u) => $u->type === 'parceiro_admin');
        $interno   = $this->users->filter(fn ($u) => in_array($u->type, ['admin', 'coordenador', 'administrativo'], true));
        $cliente   = $this->users->filter(fn ($u) => $u->type === 'cliente');

        return [
            new UsersSheet('Consultor', $consultor->values()),
            new UsersSheet('Freelance', $freelance->values()),
            new UsersSheet('Parceiro',  $parceiro->values()),
            new UsersSheet('Interno',   $interno->values()),
            new UsersSheet('Cliente',   $cliente->values()),
        ];
    }
}
