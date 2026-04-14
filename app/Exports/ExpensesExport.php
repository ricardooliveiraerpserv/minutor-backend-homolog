<?php

namespace App\Exports;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ExpensesExport implements FromCollection, WithHeadings, WithMapping
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
        $query = Expense::with(['user', 'project.customer', 'category', 'reviewedBy']);

        // Se não é admin nem tem permissão para ver todos, só pode ver os próprios
        if (!$this->user->isAdmin() && !$this->user->can('expenses.view_all')) {
            $query->where('user_id', $this->user->id);
        }

        // Aplicar os mesmos filtros da listagem
        if ($this->request->filled('search')) {
            $query->where('description', 'like', '%' . $this->request->search . '%');
        }

        if ($this->request->filled('project_id')) {
            $query->where('project_id', $this->request->project_id);
        }

        if ($this->request->filled('customer_id')) {
            $query->whereHas('project', function ($q) {
                $q->where('customer_id', $this->request->customer_id);
            });
        }

        if ($this->request->filled('user_id')) {
            $query->where('user_id', $this->request->user_id);
        }

        if ($this->request->filled('status')) {
            $query->where('status', $this->request->status);
        }

        if ($this->request->filled('expense_type')) {
            $query->where('expense_type', $this->request->expense_type);
        }

        if ($this->request->filled('category_id')) {
            $query->where('expense_category_id', $this->request->category_id);
        }

        if ($this->request->filled('start_date') && $this->request->filled('end_date')) {
            $query->whereBetween('expense_date', [$this->request->start_date, $this->request->end_date]);
        }

        // Aplicar ordenação
        $orderFields = $this->request->get('order', '-expense_date');
        if ($orderFields) {
            $fields = explode(',', $orderFields);
            foreach ($fields as $field) {
                $direction = 'asc';
                if (str_starts_with($field, '-')) {
                    $direction = 'desc';
                    $field = substr($field, 1);
                }
                
                $allowedFields = ['expense_date', 'amount', 'status', 'created_at', 'updated_at'];
                if (in_array($field, $allowedFields)) {
                    $query->orderBy($field, $direction);
                }
            }
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
            'Descrição',
            'Solicitante',
            'Projeto',
            'Cliente',
            'Categoria',
            'Valor',
            'Tipo',
            'Forma de Pagamento'
        ];
    }

    /**
     * @param $expense
     * @return array
     */
    public function map($expense): array
    {
        return [
            $expense->expense_date ? $expense->expense_date->format('d/m/Y') : '',
            $expense->status_display,
            $expense->description ?? '',
            $expense->user ? $expense->user->name : '',
            $expense->project ? $expense->project->name : '',
            $expense->project && $expense->project->customer ? $expense->project->customer->name : '',
            $expense->category ? $expense->category->name : '',
            'R$ ' . number_format($expense->amount, 2, ',', '.'),
            $expense->expense_type_display,
            $expense->payment_method_display
        ];
    }

}
