<?php

namespace Database\Factories;

use App\Models\Timesheet;
use App\Models\User;
use App\Models\Customer;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Timesheet>
 */
class TimesheetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startHour = $this->faker->numberBetween(8, 16);
        $endHour = $startHour + $this->faker->numberBetween(1, 8);
        
        return [
            'user_id' => User::factory(),
            'customer_id' => Customer::factory(),
            'project_id' => Project::factory(),
            'date' => $this->faker->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
            'start_time' => sprintf('%02d:00', $startHour),
            'end_time' => sprintf('%02d:00', min(23, $endHour)),
            'effort_minutes' => null, // Será calculado automaticamente
            'observation' => $this->faker->optional()->sentence(),
            'ticket' => $this->faker->optional()->regexify('TICKET-[0-9]{3,5}'),
            'status' => $this->faker->randomElement([
                Timesheet::STATUS_PENDING,
                Timesheet::STATUS_APPROVED,
                Timesheet::STATUS_REJECTED
            ]),
            'rejection_reason' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ];
    }

    /**
     * Indicate that the timesheet is pending.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Timesheet::STATUS_PENDING,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'rejection_reason' => null,
        ]);
    }

    /**
     * Indicate that the timesheet is approved.
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Timesheet::STATUS_APPROVED,
            'reviewed_by' => User::factory(),
            'reviewed_at' => $this->faker->dateTimeBetween('-7 days', 'now'),
            'rejection_reason' => null,
        ]);
    }

    /**
     * Indicate that the timesheet is rejected.
     */
    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Timesheet::STATUS_REJECTED,
            'reviewed_by' => User::factory(),
            'reviewed_at' => $this->faker->dateTimeBetween('-7 days', 'now'),
            'rejection_reason' => $this->faker->sentence(),
        ]);
    }

    /**
     * Indicate that the timesheet has a specific effort.
     */
    public function withEffort(int $minutes): static
    {
        $hours = intval($minutes / 60);
        $remainingMinutes = $minutes % 60;
        
        $startHour = $this->faker->numberBetween(8, 12);
        $endHour = $startHour + $hours;
        $endMinute = $remainingMinutes;
        
        return $this->state(fn (array $attributes) => [
            'start_time' => sprintf('%02d:00', $startHour),
            'end_time' => sprintf('%02d:%02d', $endHour, $endMinute),
            'effort_minutes' => $minutes,
        ]);
    }

    /**
     * Indicate a full day work (8 hours).
     */
    public function fullDay(): static
    {
        return $this->state(fn (array $attributes) => [
            'start_time' => '09:00',
            'end_time' => '17:00',
            'effort_minutes' => 480,
        ]);
    }

    /**
     * Indicate a half day work (4 hours).
     */
    public function halfDay(): static
    {
        return $this->state(fn (array $attributes) => [
            'start_time' => '09:00',
            'end_time' => '13:00',
            'effort_minutes' => 240,
        ]);
    }
} 