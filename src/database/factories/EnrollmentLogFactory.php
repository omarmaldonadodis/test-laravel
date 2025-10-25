<?php

namespace Database\Factories;

use App\Models\EnrollmentLog;
use Illuminate\Database\Eloquent\Factories\Factory;

class EnrollmentLogFactory extends Factory
{
    protected $model = EnrollmentLog::class;

    public function definition(): array
    {
        return [
            'stripe_payment_intent_id' => $this->faker->unique()->uuid(),
            'medusa_order_id' => $this->faker->optional()->uuid(),
            'customer_email' => $this->faker->safeEmail(),
            'customer_name' => $this->faker->name(),
            'moodle_user_id' => $this->faker->optional()->randomNumber(5),
            'moodle_course_id' => $this->faker->optional()->randomNumber(5),
            'status' => $this->faker->randomElement(['pending', 'success', 'failed']),
            'error_message' => $this->faker->optional()->sentence(),
            'webhook_payload' => json_encode(['example' => 'payload']),
        ];
    }
}
