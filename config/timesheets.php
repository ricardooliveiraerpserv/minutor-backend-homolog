<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Configurações de Timesheets
    |--------------------------------------------------------------------------
    |
    | Configurações relacionadas ao sistema de apontamento de horas
    |
    */

    // Período em horas para permitir estorno de aprovação
    'reversal_period_hours' => env('TIMESHEET_REVERSAL_PERIOD_HOURS', 24),

    // Horário máximo de trabalho por dia (em minutos)
    'max_hours_per_day' => env('TIMESHEET_MAX_HOURS_PER_DAY', 480), // 8 horas

    // Intervalo mínimo entre apontamentos (em minutos)
    'min_interval_between_entries' => env('TIMESHEET_MIN_INTERVAL', 15),

    // Permitir apontamentos futuros
    'allow_future_entries' => env('TIMESHEET_ALLOW_FUTURE', false),
];
