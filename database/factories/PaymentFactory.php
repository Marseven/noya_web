<?php

namespace Database\Factories;

use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = $this->faker->randomFloat(2, 10, 1000);
        $fees = $amount * 0.03; // 3% fees
        
        return [
            'order_id' => Order::factory(),
            'amount' => $amount,
            'partner_name' => $this->faker->randomElement(['PayPal', 'Stripe', 'Square', 'Razorpay']),
            'partner_fees' => $fees,
            'total_amount' => $amount + $fees,
            'status' => $this->faker->randomElement(['PAID', 'INIT']),
            'partner_reference' => $this->faker->unique()->regexify('[A-Z]{2}[0-9]{8}'),
            'callback_data' => [
                'transaction_id' => $this->faker->uuid(),
                'gateway' => $this->faker->randomElement(['paypal', 'stripe', 'square']),
                'timestamp' => $this->faker->iso8601(),
            ],
        ];
    }

    /**
     * Indicate that the payment is paid.
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'PAID',
        ]);
    }

    /**
     * Indicate that the payment is pending.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'INIT',
        ]);
    }
}