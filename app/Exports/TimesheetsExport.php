<?php

namespace App\Exports;

use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class TimesheetsExport implements FromCollection, WithHeadings, WithMapping
{
    protected $request;
    protected $user;

    public function __construct(Request $request, User $user)
    {
        $this->request = $request;
        $this->user = $user;
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    public function collection()
    {
        $query = Timesheet::with(['user', 'customer', 'project', 'reviewedBy']);

        // Se não é admin nem tem permissão para ver todos, só pode ver os próprios
        if (!$this->user->isAdmin() && !$this->user->can('hours.view_all')) {
            $query->forUser($this->user->id);
        }

        // Aplicar os mesmos filtros da listagem
        if ($this->request->filled('project_id')) {
            $query->forProject($this->request->project_id);
        }

        if ($this->request->filled('customer_id')) {
            $query->whereHas('project', function ($q) {
                $q->where('customer_id', $this->request->customer_id);
            });
        }

        if ($this->request->filled('user_id') && ($this->user->isAdmin() || $this->user->can('hours.view_all'))) {
            $query->forUser($this->request->user_id);
        }

        if ($this->request->filled('status')) {
            $query->withStatus($this->request->status);
        }

        if ($this->request->filled('ticket')) {
            $query->where('ticket', 'like', "%{$this->request->ticket}%");
        }

        if ($this->request->filled('start_date') && $this->request->filled('end_date')) {
            $query->inPeriod($this->request->start_date, $this->request->end_date);
        }

        // Busca geral
        if ($this->request->filled('search')) {
            $search = $this->request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('observation', 'like', "%{$search}%")
                  ->orWhere('ticket', 'like', "%{$search}%")
                  ->orWhereHas('project', function ($pq) use ($search) {
                      $pq->where('name', 'like', "%{$search}%");
                  })
                  ->orWhereHas('customer', function ($cq) use ($search) {
                      $cq->where('name', 'like', "%{$search}%");
                  });
            });
        }

        // Aplicar ordenação
        if ($this->request->has('order')) {
            $orderFields = explode(',', $this->request->get('order'));
            foreach ($orderFields as $field) {
                if (str_starts_with($field, '-')) {
                    $query->orderBy(substr($field, 1), 'desc');
                } else {
                    $query->orderBy($field, 'asc');
                }
            }
        } else {
            $query->orderBy('date', 'desc')->orderBy('start_time', 'desc');
        }

        return $query->get();
    }

    /**
     * @return array
     */
    public function headings(): array
    {
        return [
            'Data',
            'Status',
            'Colaborador',
            'Projeto',
            'Cliente',
            'Ticket',
            'Início',
            'Fim',
            'Horas',
            'Observações'
        ];
    }

    /**
     * @param $timesheet
     * @return array
     */
    public function map($timesheet): array
    {
        return [
            $timesheet->date ? $timesheet->date->format('d/m/Y') : '',
            $timesheet->status_display,
            $timesheet->user ? $timesheet->user->name : '',
            $timesheet->project ? $timesheet->project->name : '',
            $timesheet->customer ? $timesheet->customer->name : '',
            $timesheet->ticket ?? '',
            $timesheet->start_time ? \Carbon\Carbon::parse($timesheet->start_time)->format('H:i') : '',
            $timesheet->end_time ? \Carbon\Carbon::parse($timesheet->end_time)->format('H:i') : '',
            $timesheet->effort_hours,
            $timesheet->observation ?? ''
        ];
    }

}
