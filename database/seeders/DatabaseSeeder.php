<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        /**
         * Separação clara por ambiente:
         *
         * - Produção: somente seeders essenciais (CoreSeeder)
         * - Demais ambientes (local, staging, etc.): seeders essenciais + massa de dados (DevDemoSeeder)
         */
        $this->call([
            CoreSeeder::class,
        ]);

        if (! app()->environment('production')) {
            $this->call([
                DevDemoSeeder::class,
            ]);
        }
    }
}
