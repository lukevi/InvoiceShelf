<?php

namespace Database\Factories;

use App\Domains\Accounts\Models\User;
use App\Domains\Contacts\Models\Customer;
use App\Domains\Purchases\Models\ExpenseCategory;
use App\Domains\WorkLog\Models\WorkLog;
use Illuminate\Database\Eloquent\Factories\Factory;

class WorkLogFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = WorkLog::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'charge_category_id' => ExpenseCategory::factory(),
            'description' => $this->faker->sentence(),
            'duration_hours' => $this->faker->randomFloat(2, 0.25, 8),
            'company_id' => User::find(1)->companies()->first()->id,
        ];
    }
}
