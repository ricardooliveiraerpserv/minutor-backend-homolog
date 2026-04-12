<?php

namespace Database\Seeders;

use App\Models\Holiday;
use Illuminate\Database\Seeder;

class HolidaySeeder extends Seeder
{
    public function run(): void
    {
        $holidays = [
            // ── 2024 ──────────────────────────────────────────────────────────
            ['date' => '2024-01-01', 'name' => 'Confraternização Universal'],
            ['date' => '2024-02-12', 'name' => 'Carnaval'],
            ['date' => '2024-02-13', 'name' => 'Carnaval'],
            ['date' => '2024-03-29', 'name' => 'Sexta-feira Santa'],
            ['date' => '2024-03-31', 'name' => 'Páscoa'],
            ['date' => '2024-04-21', 'name' => 'Tiradentes'],
            ['date' => '2024-05-01', 'name' => 'Dia do Trabalho'],
            ['date' => '2024-05-30', 'name' => 'Corpus Christi'],
            ['date' => '2024-09-07', 'name' => 'Independência do Brasil'],
            ['date' => '2024-10-12', 'name' => 'Nossa Senhora Aparecida'],
            ['date' => '2024-11-02', 'name' => 'Finados'],
            ['date' => '2024-11-15', 'name' => 'Proclamação da República'],
            ['date' => '2024-11-20', 'name' => 'Dia da Consciência Negra'],
            ['date' => '2024-12-25', 'name' => 'Natal'],

            // ── 2025 ──────────────────────────────────────────────────────────
            ['date' => '2025-01-01', 'name' => 'Confraternização Universal'],
            ['date' => '2025-03-04', 'name' => 'Carnaval'],
            ['date' => '2025-03-05', 'name' => 'Carnaval'],
            ['date' => '2025-04-18', 'name' => 'Sexta-feira Santa'],
            ['date' => '2025-04-20', 'name' => 'Páscoa'],
            ['date' => '2025-04-21', 'name' => 'Tiradentes'],
            ['date' => '2025-05-01', 'name' => 'Dia do Trabalho'],
            ['date' => '2025-06-19', 'name' => 'Corpus Christi'],
            ['date' => '2025-09-07', 'name' => 'Independência do Brasil'],
            ['date' => '2025-10-12', 'name' => 'Nossa Senhora Aparecida'],
            ['date' => '2025-11-02', 'name' => 'Finados'],
            ['date' => '2025-11-15', 'name' => 'Proclamação da República'],
            ['date' => '2025-11-20', 'name' => 'Dia da Consciência Negra'],
            ['date' => '2025-12-25', 'name' => 'Natal'],

            // ── 2026 ──────────────────────────────────────────────────────────
            ['date' => '2026-01-01', 'name' => 'Confraternização Universal'],
            ['date' => '2026-02-17', 'name' => 'Carnaval'],
            ['date' => '2026-02-18', 'name' => 'Carnaval'],
            ['date' => '2026-04-03', 'name' => 'Sexta-feira Santa'],
            ['date' => '2026-04-05', 'name' => 'Páscoa'],
            ['date' => '2026-04-21', 'name' => 'Tiradentes'],
            ['date' => '2026-05-01', 'name' => 'Dia do Trabalho'],
            ['date' => '2026-06-04', 'name' => 'Corpus Christi'],
            ['date' => '2026-09-07', 'name' => 'Independência do Brasil'],
            ['date' => '2026-10-12', 'name' => 'Nossa Senhora Aparecida'],
            ['date' => '2026-11-02', 'name' => 'Finados'],
            ['date' => '2026-11-15', 'name' => 'Proclamação da República'],
            ['date' => '2026-11-20', 'name' => 'Dia da Consciência Negra'],
            ['date' => '2026-12-25', 'name' => 'Natal'],
        ];

        foreach ($holidays as $h) {
            Holiday::firstOrCreate(
                ['date' => $h['date']],
                array_merge($h, ['type' => 'national', 'active' => true])
            );
        }

        $this->command->info('✅ ' . count($holidays) . ' feriados criados/verificados (2024-2026)');
    }
}
