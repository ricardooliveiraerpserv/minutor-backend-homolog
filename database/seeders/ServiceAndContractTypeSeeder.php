<?php

namespace Database\Seeders;

use App\Models\ServiceType;
use App\Models\ContractType;
use Illuminate\Database\Seeder;

class ServiceAndContractTypeSeeder extends Seeder
{
    /**
     * Seed de tipos básicos de serviço e contrato.
     *
     * Estes registros são usados nas regras de negócio e devem existir
     * em qualquer ambiente (produção, homologação, desenvolvimento).
     */
    public function run(): void
    {
        $this->seedServiceTypes();
        $this->seedContractTypes();
    }

    private function seedServiceTypes(): void
    {
        $types = [
            [
                'name' => 'Projeto',
                'code' => 'projeto',
                'description' => 'Projetos de desenvolvimento',
            ],
            [
                'name' => 'Sustentação',
                'code' => 'sustentacao',
                'description' => 'Serviços de sustentação',
            ],
        ];

        foreach ($types as $type) {
            ServiceType::firstOrCreate(
                ['code' => $type['code']],
                array_merge($type, ['active' => true])
            );
        }
    }

    private function seedContractTypes(): void
    {
        $types = [
            [
                'name' => 'Fechado',
                'code' => 'closed',
                'description' => 'Projeto fechado',
            ],
            [
                'name' => 'Banco de Horas Fixo',
                'code' => 'fixed_hours',
                'description' => 'Banco de horas fixo',
            ],
            [
                'name' => 'Banco de Horas Mensal',
                'code' => 'monthly_hours',
                'description' => 'Banco de horas mensal',
            ],
            [
                'name' => 'On Demand',
                'code' => 'on_demand',
                'description' => 'Sob demanda',
            ],
        ];

        foreach ($types as $type) {
            ContractType::firstOrCreate(
                ['code' => $type['code']],
                array_merge($type, ['active' => true])
            );
        }
    }
}

