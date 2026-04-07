<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Customer;
use App\Models\ServiceType;
use App\Models\ContractType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->sentence(3),
            'code' => $this->faker->regexify('[A-Z]{2,4}-[0-9]{3}'),
            'description' => $this->faker->paragraph(),
            'customer_id' => Customer::factory(),
            'project_value' => $this->faker->randomFloat(2, 10000, 500000),
            'hourly_rate' => $this->faker->randomFloat(2, 50, 300),
            'sold_hours' => $this->faker->numberBetween(40, 2000),
            'hour_contribution' => $this->faker->numberBetween(0, 100),
            'additional_hourly_rate' => $this->faker->randomFloat(2, 0, 100),
            'start_date' => $this->faker->dateTimeBetween('-1 year', '+6 months'),
            'max_expense_per_consultant' => $this->faker->randomFloat(2, 100, 5000),
            'expense_responsible_party' => $this->faker->randomElement(['consultancy', 'client']),
            'service_type_id' => ServiceType::factory(),
            'contract_type_id' => ContractType::factory(),
            'status' => $this->faker->randomElement([
                Project::STATUS_AWAITING_START,
                Project::STATUS_STARTED,
                Project::STATUS_PAUSED,
                Project::STATUS_CANCELLED,
                Project::STATUS_FINISHED,
            ]),
        ];
    }

    /**
     * Indicate that the project is active.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $this->faker->randomElement([
                Project::STATUS_AWAITING_START,
                Project::STATUS_STARTED,
                Project::STATUS_PAUSED,
            ]),
        ]);
    }

    /**
     * Indicate that the project is started.
     */
    public function started(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Project::STATUS_STARTED,
        ]);
    }

    /**
     * Indicate that the project is finished.
     */
    public function finished(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Project::STATUS_FINISHED,
        ]);
    }
}
