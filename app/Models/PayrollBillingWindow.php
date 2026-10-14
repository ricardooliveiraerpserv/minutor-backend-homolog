<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Janela de pagamento (ranges) por colaborador. Ver migration create_payroll_billing_windows.
 */
class PayrollBillingWindow extends Model
{
    protected $table = 'payroll_billing_windows';

    protected $fillable = ['holder_type', 'holder_id', 'start_month', 'end_month', 'note'];

    public const TYPE_USER = 'user';
    public const TYPE_PARTNER = 'partner';

    /** Uma janela cobre a competência YYYY-MM? (nulos = aberto). */
    public function covers(string $ym): bool
    {
        return ($this->start_month === null || $this->start_month <= $ym)
            && ($this->end_month === null || $this->end_month >= $ym);
    }

    /**
     * Dos ids informados, quais NÃO são pagáveis na competência (têm janela(s) mas nenhuma cobre).
     * Ids sem nenhuma janela NÃO entram (pagáveis por padrão = comportamento legado).
     */
    public static function nonPayableIds(string $holderType, array $ids, string $ym): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (empty($ids)) {
            return [];
        }
        $byHolder = static::where('holder_type', $holderType)
            ->whereIn('holder_id', $ids)
            ->get(['holder_id', 'start_month', 'end_month'])
            ->groupBy('holder_id');

        $non = [];
        foreach ($byHolder as $hid => $windows) {
            $covered = $windows->contains(fn ($w) => $w->covers($ym));
            if (!$covered) {
                $non[] = (int) $hid;
            }
        }
        return $non;
    }

    public static function isPayable(string $holderType, int $id, string $ym): bool
    {
        return !in_array($id, static::nonPayableIds($holderType, [$id], $ym), true);
    }

    /** Janelas de um colaborador, ordenadas. */
    public static function windowsFor(string $holderType, int $id): Collection
    {
        return static::where('holder_type', $holderType)->where('holder_id', $id)
            ->orderByRaw('COALESCE(start_month, \'0000-00\')')->get();
    }

    /**
     * INATIVAR o pagamento até $endMonth (inclusivo): fecha a janela ATIVA (end null) nesse mês;
     * se não houver janela aberta e nenhuma janela ainda, cria {null, X}. Idempotente.
     */
    public static function closeAt(string $holderType, int $id, string $endMonth, ?string $note = null): void
    {
        $open = static::where('holder_type', $holderType)->where('holder_id', $id)->whereNull('end_month')->get();
        if ($open->isNotEmpty()) {
            foreach ($open as $w) {
                $w->end_month = $endMonth;
                if ($note) $w->note = $note;
                $w->save();
            }
            return;
        }
        // Sem janela aberta: se já existe histórico, não mexe (já estava "fechado"). Senão cria {null, X}.
        $any = static::where('holder_type', $holderType)->where('holder_id', $id)->exists();
        if (!$any) {
            static::create(['holder_type' => $holderType, 'holder_id' => $id, 'start_month' => null, 'end_month' => $endMonth, 'note' => $note]);
        }
    }

    /** REATIVAR o pagamento a partir de $fromMonth (pode ser retroativo): abre nova janela {Y, null}. */
    public static function resumeFrom(string $holderType, int $id, string $fromMonth, ?string $note = null): void
    {
        // Evita duplicar: se já há janela aberta começando em <= Y, não precisa.
        $alreadyOpen = static::where('holder_type', $holderType)->where('holder_id', $id)
            ->whereNull('end_month')
            ->where(fn ($q) => $q->whereNull('start_month')->orWhere('start_month', '<=', $fromMonth))
            ->exists();
        if ($alreadyOpen) {
            return;
        }
        static::create(['holder_type' => $holderType, 'holder_id' => $id, 'start_month' => $fromMonth, 'end_month' => null, 'note' => $note]);
    }

    /** Resumo p/ o FE: pagável hoje? até quando (fim da janela aberta / última)? desde quando? */
    public static function summary(string $holderType, int $id, string $currentYm): array
    {
        $windows = static::windowsFor($holderType, $id);
        $hasWindows = $windows->isNotEmpty();
        $payableNow = !$hasWindows || $windows->contains(fn ($w) => $w->covers($currentYm));
        $open = $windows->firstWhere('end_month', null);
        $lastEnd = $windows->whereNotNull('end_month')->max('end_month');
        return [
            'has_windows'  => $hasWindows,
            'payable_now'  => $payableNow,
            'paid_until'   => $open ? null : $lastEnd,                 // null = sem fim (ativo) OU sem janela
            'paid_from'    => $open?->start_month,                      // início da janela ativa
            'windows'      => $windows->map(fn ($w) => ['start_month' => $w->start_month, 'end_month' => $w->end_month, 'note' => $w->note])->values(),
        ];
    }
}
