<?php

namespace Database\Seeders;

use App\Models\SystemSetting;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class SystemSettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Configurações de Apontamento de Horas
        SystemSetting::set(
            key: 'timesheet_retroactive_limit_days',
            value: 7,
            type: 'integer',
            group: 'timesheets',
            description: 'Quantidade de dias após a data do serviço que o consultor pode lançar horas'
        );

        $this->command->info('✅ Configurações do sistema criadas com sucesso!');
        $this->command->info('📊 Configurações criadas:');
        $this->command->info('   - timesheet_retroactive_limit_days: 7 dias');
    }
}

