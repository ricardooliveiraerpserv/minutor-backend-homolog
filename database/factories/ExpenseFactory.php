<?php

namespace Database\Factories;

use App\Models\Expense;
use App\Models\User;
use App\Models\Project;
use App\Models\ExpenseCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Expense>
 */
class ExpenseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'project_id' => Project::factory(),
            'expense_category_id' => ExpenseCategory::factory(),
            'expense_date' => $this->faker->dateTimeBetween('-30 days', 'today'),
            'description' => $this->faker->sentence(),
            'amount' => $this->faker->randomFloat(2, 10, 1000),
            'expense_type' => $this->faker->randomElement([
                Expense::TYPE_CORPORATE_CARD,
                Expense::TYPE_REIMBURSEMENT
            ]),
            'payment_method' => $this->faker->randomElement([
                Expense::PAYMENT_CORPORATE_CARD,
                Expense::PAYMENT_CASH,
                Expense::PAYMENT_BANK_TRANSFER,
                Expense::PAYMENT_PIX,
                Expense::PAYMENT_CHECK,
                Expense::PAYMENT_OTHER
            ]),
            'receipt_path' => null,
            'receipt_original_name' => null,
            'status' => Expense::STATUS_PENDING,
            'rejection_reason' => null,
            'charge_client' => false,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ];
    }

    /**
     * Estado pendente
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Expense::STATUS_PENDING,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'rejection_reason' => null,
            'charge_client' => false,
        ]);
    }

    /**
     * Estado aprovado
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Expense::STATUS_APPROVED,
            'reviewed_by' => User::factory(),
            'reviewed_at' => $this->faker->dateTimeBetween('-7 days', 'now'),
            'rejection_reason' => null,
            'charge_client' => $this->faker->boolean(),
        ]);
    }

    /**
     * Estado rejeitado
     */
    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Expense::STATUS_REJECTED,
            'reviewed_by' => User::factory(),
            'reviewed_at' => $this->faker->dateTimeBetween('-7 days', 'now'),
            'rejection_reason' => $this->faker->sentence(),
            'charge_client' => false,
        ]);
    }

    /**
     * Estado com ajuste solicitado
     */
    public function adjustmentRequested(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Expense::STATUS_ADJUSTMENT_REQUESTED,
            'reviewed_by' => User::factory(),
            'reviewed_at' => $this->faker->dateTimeBetween('-7 days', 'now'),
            'rejection_reason' => $this->faker->sentence(),
            'charge_client' => false,
        ]);
    }

    /**
     * Despesa de cartão corporativo
     */
    public function corporateCard(): static
    {
        return $this->state(fn (array $attributes) => [
            'expense_type' => Expense::TYPE_CORPORATE_CARD,
            'payment_method' => Expense::PAYMENT_CORPORATE_CARD,
        ]);
    }

    /**
     * Despesa de reembolso
     */
    public function reimbursement(): static
    {
        return $this->state(fn (array $attributes) => [
            'expense_type' => Expense::TYPE_REIMBURSEMENT,
            'payment_method' => $this->faker->randomElement([
                Expense::PAYMENT_CASH,
                Expense::PAYMENT_BANK_TRANSFER,
                Expense::PAYMENT_PIX,
                Expense::PAYMENT_CHECK,
                Expense::PAYMENT_OTHER
            ]),
        ]);
    }

    /**
     * Com comprovante
     */
    public function withReceipt(): static
    {
        return $this->state(fn (array $attributes) => [
            'receipt_path' => 'receipts/2024/01/receipt_' . $this->faker->uuid . '.pdf',
            'receipt_original_name' => 'comprovante_' . $this->faker->word . '.pdf',
        ]);
    }

    /**
     * Despesa de transporte
     */
    public function transport(): static
    {
        return $this->state(fn (array $attributes) => [
            'description' => $this->faker->randomElement([
                'Táxi para reunião com cliente',
                'Uber para o aeroporto',
                'Gasolina viagem a trabalho',
                'Estacionamento shopping center',
                'Pedágio rodovia'
            ]),
            'amount' => $this->faker->randomFloat(2, 15, 200),
        ]);
    }

    /**
     * Despesa de alimentação
     */
    public function food(): static
    {
        return $this->state(fn (array $attributes) => [
            'description' => $this->faker->randomElement([
                'Almoço durante viagem',
                'Jantar com cliente',
                'Café da manhã no hotel',
                'Lanche durante reunião'
            ]),
            'amount' => $this->faker->randomFloat(2, 25, 150),
        ]);
    }
}
