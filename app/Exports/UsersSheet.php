<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Uma aba do export de usuários (Consultor / Freelance / Parceiro / Interno / Cliente).
 * Traz TODOS os campos relevantes do usuário (cadastro + folha + perfil + vínculos).
 */
class UsersSheet implements FromArray, WithHeadings, WithTitle, WithStyles, ShouldAutoSize
{
    /** @param Collection<int,\App\Models\User> $users */
    public function __construct(protected string $sheetTitle, protected Collection $users) {}

    public function title(): string
    {
        return $this->sheetTitle;
    }

    public function headings(): array
    {
        return [
            'ID', 'Nome', 'Categoria', 'Nome completo', 'E-mail', 'Ativo', 'Inativado em',
            'Tipo', 'Vínculo', 'Tipo consultor', 'Tipo contrato', 'Coordenador', 'Tipo coordenador',
            'Executivo', 'Diretor', 'Diretor Projetos', 'Bizify', 'Coord. Bizify',
            'CPF', 'Matrícula', 'Status folha',
            'Valor hora', 'Tipo taxa', 'Horas/dia', 'Horas garantidas',
            'Saldo BH inicial', 'Início BH',
            'Parceiro', 'Cliente',
            'Telefone', 'LinkedIn', 'Modelo trabalho', 'Cidade', 'Estado', 'CEP',
            'Bairro', 'Logradouro', 'Número', 'Complemento',
            'Nascimento', 'Disponibilidade', 'Início disponibilidade',
            'Empresa atual', 'Empresa origem', 'Criado em',
        ];
    }

    public function array(): array
    {
        $b   = fn ($v) => $v ? 'Sim' : 'Não';
        $d   = fn ($v) => $v ? (string) \Illuminate\Support\Str::of((string) $v)->before('T') : '';
        $dt  = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d/m/Y H:i') : '';
        // Normaliza texto: colapsa espaços/quebras embutidos (dados sujos infam a largura da coluna).
        $c   = fn ($v) => is_string($v) ? trim(preg_replace('/\s+/u', ' ', $v)) : $v;
        // Categoria granular do usuário (Parceiro/Freelance/Interno/Coordenador/Admin/...).
        $cat = fn ($u) => match (true) {
            $u->type === 'cliente'        => 'Cliente',
            $u->type === 'parceiro_admin' => 'Parceiro',
            $u->type === 'admin'          => 'Admin',
            $u->type === 'coordenador'    => 'Coordenador',
            $u->type === 'administrativo' => 'Administrativo',
            $u->type === 'consultor' && $u->work_bond === 'freelance' => 'Freelance',
            $u->type === 'consultor'      => 'Interno',
            default                       => (string) ($u->type ?? '—'),
        };

        return $this->users->map(function ($u) use ($b, $d, $dt, $c, $cat) {
            return array_map($c, [
                $u->id,
                $u->name,
                $cat($u),
                $u->full_name,
                $u->email,
                $b($u->enabled),
                $dt($u->auto_inactivated_at),
                $u->type,
                $u->work_bond,
                $u->consultant_type,
                $u->contract_type,
                $b($u->is_coordinator),
                $u->coordinator_type,
                $b($u->is_executive),
                $b($u->is_diretor),
                $b($u->is_diretor_projetos),
                $b($u->is_bizify),
                $b($u->is_bizify_coordinator),
                $u->cpf,
                $u->matricula,
                $u->payroll_status,
                $u->hourly_rate,
                $u->rate_type,
                $u->daily_hours,
                $u->guaranteed_hours,
                $u->bank_hours_initial_balance,
                $d($u->bank_hours_start_date),
                $u->partner?->name,
                $u->customer?->name,
                $u->phone,
                $u->linkedin_url,
                $u->work_model,
                $u->city,
                $u->state,
                $u->cep,
                $u->neighborhood,
                $u->address_street,
                $u->address_number,
                $u->address_complement,
                $d($u->birth_date),
                $u->availability_status,
                $d($u->availability_start_date),
                $u->currentCompany?->name ?? null,
                $u->homeCompany?->name ?? null,
                $dt($u->created_at),
            ]);
        })->values()->all();
    }

    public function styles(Worksheet $sheet): array
    {
        // Cabeçalho em negrito com fundo cinza.
        $sheet->getStyle('1:1')->getFont()->setBold(true);
        $sheet->getStyle('1:1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E5E7EB');
        $sheet->freezePane('A2');
        return [];
    }
}
