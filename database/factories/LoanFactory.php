<?php

namespace Database\Factories;

use App\Models\Loan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class LoanFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Loan::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition(): array
    {
        return [
            'user_id' => fn() => User::factory()->create(),
            'terms' => $this->faker->numberBetween(1, 12),
            'amount' => $this->faker->numberBetween(1000, 10000),
            'outstanding_amount' => 0,
            'currency_code' => Loan::CURRENCY_VND,
            'processed_at' => now()->format('Y-m-d'),
            'status' => Loan::STATUS_DUE,
        ];
    }
}
