<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class CoreSeeder extends Seeder
{
    /**
     * Seeders essenciais para qualquer ambiente (inclui produção).
     */
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            UserSeeder::class,
            ExpenseCategorySeeder::class,
            SystemSettingSeeder::class,
            ServiceAndContractTypeSeeder::class,
        ]);
    }
}

