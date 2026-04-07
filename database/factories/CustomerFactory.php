<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'cgc' => $this->faker->randomElement([
                $this->faker->numerify('###########'), // CPF (11 dígitos)
                $this->faker->numerify('##############'), // CNPJ (14 dígitos)
            ]),
        ];
    }

    /**
     * Indicate that the customer has a CPF.
     */
    public function withCpf(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => $this->faker->name(),
            'cgc' => $this->faker->numerify('###########'), // CPF válido
        ]);
    }

    /**
     * Indicate that the customer has a CNPJ.
     */
    public function withCnpj(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => $this->faker->company(),
            'cgc' => $this->faker->numerify('##############'), // CNPJ válido
        ]);
    }
}
