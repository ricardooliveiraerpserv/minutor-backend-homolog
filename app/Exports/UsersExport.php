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
    /**
     * @param Collection<int,User> $users
     * @param array<int,string>|null $only  Abas a incluir (ex.: ['Todos','Interno']); null = todas.
     */
    public function __construct(protected Collection $users, protected ?array $only = null) {}

    public function sheets(): array
    {
        // Interno = equipe ERPSERV + consultor NÃO-freelance (vínculo fixo/sem vínculo = empregado).
        $interno   = $this->users->filter(fn ($u) => in_array($u->type, ['admin', 'coordenador', 'administrativo'], true)
            || ($u->type === 'consultor' && $u->work_bond !== 'freelance'));
        $freelance = $this->users->filter(fn ($u) => $u->type === 'consultor' && $u->work_bond === 'freelance');
        $parceiro  = $this->users->filter(fn ($u) => $u->type === 'parceiro_admin');
        $cliente   = $this->users->filter(fn ($u) => $u->type === 'cliente');

        $all = [
            // Aba "Todos": todos os usuários com a coluna Categoria (granular) p/ filtrar/ordenar.
            'Todos'     => $this->users->values(),
            'Interno'   => $interno->values(),
            'Freelance' => $freelance->values(),
            'Parceiro'  => $parceiro->values(),
            'Cliente'   => $cliente->values(),
        ];

        $sheets = [];
        foreach ($all as $title => $rows) {
            if ($this->only !== null && !in_array($title, $this->only, true)) {
                continue; // aba não escolhida na tela de perguntas
            }
            $sheets[] = new UsersSheet($title, $rows);
        }
        // Garante ao menos 1 aba (evita xlsx inválido se a seleção não bater nada).
        if (empty($sheets)) {
            $sheets[] = new UsersSheet('Todos', $this->users->values());
        }

        return $sheets;
    }
}
