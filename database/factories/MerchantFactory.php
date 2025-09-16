<?php

namespace Database\Factories;

use App\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Merchant>
 */
class MerchantFactory extends Factory
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
            'address' => $this->faker->address(),
            'entity_file' => $this->faker->optional()->word() . '.pdf',
            'other_document_file' => $this->faker->optional()->word() . '.pdf',
            'tel' => $this->faker->phoneNumber(),
            'email' => $this->faker->unique()->companyEmail(),
            'merchant_parent_id' => null,
            'status' => $this->faker->randomElement(['PENDING', 'BLOCKED', 'APPROVED', 'SUSPENDED']),
            'type' => $this->faker->randomElement(['Distributor', 'Wholesaler', 'Subwholesaler', 'PointOfSell']),
            'lat' => $this->faker->latitude(),
            'long' => $this->faker->longitude(),
        ];
    }

    /**
     * Indicate that the merchant is approved.
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'APPROVED',
        ]);
    }

    /**
     * Indicate that the merchant is a child of another merchant.
     */
    public function child(): static
    {
        return $this->state(fn (array $attributes) => [
            'merchant_parent_id' => Merchant::factory(),
            'type' => $this->faker->randomElement(['Subwholesaler', 'PointOfSell']),
        ]);
    }
}