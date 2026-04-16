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

    public function collection()
    {
        $query = Timesheet::with(['user', 'customer', 'project'])
            ->leftJoin('movidesk_tickets', 'timesheets.ticket', '=', 'movidesk_tickets.ticket_id')
            ->select(
                'timesheets.*',
                'movidesk_tickets.titulo as ticket_titulo',
                'movidesk_tickets.solicitante as ticket_solicitante'
            );

        if (!$this->user->isAdmin() && !$this->user->isCoordenador() && !$this->user->hasAccess('hours.view_all')) {
            if ($this->user->isCliente() && $this->user->customer_id) {
                $query->whereHas('project', fn($q) => $q->where('customer_id', $this->user->customer_id));
            } else {
                $query->forUser($this->user->id);
            }
        }

        if ($this->request->filled('project_id')) {
            $query->forProject($this->request->project_id);
        }

        if ($this->request->filled('customer_id')) {
            $query->whereHas('project', function ($q) {
                $q->where('customer_id', $this->request->customer_id);
            });
        }

        if ($this->request->filled('user_id') && ($this->user->isAdmin() || $this->user->hasAccess('hours.view_all'))) {
            $query->forUser($this->request->user_id);
        }

        if ($this->request->filled('status')) {
            $query->withStatus($this->request->status);
        }

        if ($this->request->filled('ticket')) {
            $query->where('timesheets.ticket', 'like', "%{$this->request->ticket}%");
        }

        if ($this->request->filled('start_date') && $this->request->filled('end_date')) {
            $query->inPeriod($this->request->start_date, $this->request->end_date);
        }

        if ($this->request->filled('requester')) {
            $query->whereRaw("movidesk_tickets.solicitante::jsonb->>'name' = ?", [$this->request->requester]);
        }

        if ($this->request->filled('ticket_service')) {
            $query->where('movidesk_tickets.servico', $this->request->ticket_service);
        }

        if ($this->request->filled('search')) {
            $search = $this->request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('timesheets.observation', 'like', "%{$search}%")
                  ->orWhere('timesheets.ticket', 'like', "%{$search}%")
                  ->orWhereHas('project', fn($pq) => $pq->where('name', 'like', "%{$search}%"))
                  ->orWhereHas('customer', fn($cq) => $cq->where('name', 'like', "%{$search}%"));
            });
        }

        $query->orderBy('timesheets.date', 'desc')->orderBy('timesheets.start_time', 'desc');

        return $query->get();
    }

    public function headings(): array
    {
        return [
            'Data',
            'Ticket',
            'Colaborador',
            'Projeto',
            'Título',
            'Descrição',
            'Solicitante',
            'Horas',
            'Status',
        ];
    }

    public function map($timesheet): array
    {
        $solicitante = null;
        if ($timesheet->ticket_solicitante) {
            $s = is_string($timesheet->ticket_solicitante)
                ? json_decode($timesheet->ticket_solicitante, true)
                : $timesheet->ticket_solicitante;
            $solicitante = $s['name'] ?? null;
            if ($solicitante && !empty($s['organization'])) {
                $solicitante .= ' — ' . $s['organization'];
            }
        }

        $descricao = $timesheet->observation
            ? trim(preg_replace('/\s+/', ' ', strip_tags($timesheet->observation)))
            : '';

        return [
            $timesheet->date ? $timesheet->date->format('d/m/Y') : '',
            $timesheet->ticket ?? '',
            $timesheet->user ? $timesheet->user->name : '',
            $timesheet->project ? $timesheet->project->name : '',
            $timesheet->ticket_titulo ?? '',
            $descricao,
            $solicitante ?? '',
            $timesheet->effort_hours ?? '',
            $timesheet->status_display ?? $timesheet->status ?? '',
        ];
    }
}
