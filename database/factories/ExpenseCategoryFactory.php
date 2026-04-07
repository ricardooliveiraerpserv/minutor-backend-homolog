<?php

namespace Database\Factories;

use App\Models\ExpenseCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ExpenseCategory>
 */
class ExpenseCategoryFactory extends Factory
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
                'Transporte',
                'Alimentação', 
                'Hospedagem',
                'Material de Escritório',
                'Representação'
            ]),
            'code' => $this->faker->unique()->slug(),
            'description' => $this->faker->sentence(),
            'parent_id' => null,
            'is_active' => true,
            'sort_order' => $this->faker->numberBetween(1, 10),
        ];
    }

    /**
     * Subcategoria
     */
    public function subcategory(): static
    {
        return $this->state(fn (array $attributes) => [
            'parent_id' => ExpenseCategory::factory(),
        ]);
    }

    /**
     * Categoria inativa
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
