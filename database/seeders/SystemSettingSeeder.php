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

        // Fechamento automático de competência — dia útil de encerramento
        SystemSetting::set(
            key: 'fechamento_auto_dia_util',
            value: 2,
            type: 'integer',
            group: 'fechamento',
            description: 'Nº do dia útil do mês em que a competência do mês anterior é encerrada automaticamente (pula fins de semana e feriados)'
        );

        $this->command->info('✅ Configurações do sistema criadas com sucesso!');
        $this->command->info('📊 Configurações criadas:');
        $this->command->info('   - timesheet_retroactive_limit_days: 7 dias');
        $this->command->info('   - fechamento_auto_dia_util: 2º dia útil');
    }
}

