<?php

namespace Database\Factories;

use App\Models\ServiceType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ServiceType>
 */
class ServiceTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->randomElement([
                'Desenvolvimento',
                'Consultoria',
                'Suporte',
                'Manutenção',
                'Implementação',
                'Migração',
                'Treinamento',
            ]),
            'description' => $this->faker->sentence(),
        ];
    }
}
