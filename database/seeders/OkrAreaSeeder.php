<?php

namespace Database\Seeders;

use App\Models\OkrArea;
use Illuminate\Database\Seeder;

/**
 * Áreas iniciais do OKR (ERPSERV). Idempotente — não duplica se já existir pelo
 * nome. As áreas permanecem cadastráveis; isto é apenas a semente padrão.
 */
class OkrAreaSeeder extends Seeder
{
    public function run(): void
    {
        $areas = [
            'Comercial',
            'Customer Success',
            'Consultoria',
            'Desenvolvimento',
            'Financeiro',
            'Marketing',
            'Administrativo',
        ];

        foreach ($areas as $name) {
            OkrArea::firstOrCreate(['name' => $name], ['active' => true]);
        }
    }
}
