<?php

namespace Database\Factories;

use App\Models\ContractType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ContractType>
 */
class ContractTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->randomElement([
                'Hora Técnica',
                'Valor Fixo', 
                'Escopo Fechado',
                'Outsourcing',
                'Suporte Dedicado',
                'Consultoria',
                'Desenvolvimento',
                'Manutenção',
                'Projeto Fechado',
                'Banco de Horas',
            ]),
            'code' => $this->faker->unique()->regexify('[A-Z]{2,3}'),
            'description' => $this->faker->sentence(),
            'active' => true,
        ];
    }
}
