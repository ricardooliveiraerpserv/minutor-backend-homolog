<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DevDemoSeeder extends Seeder
{
    /**
     * Seeders de massa de dados para desenvolvimento / demo.
     *
     * Não devem ser executados em produção.
     */
    public function run(): void
    {
        $this->call([
            DashboardDataSeeder::class,
            TimesheetSeeder::class,
            // MinimalDashboardDataSeeder::class, // habilite se quiser cenário mínimo adicional
        ]);
    }
}

