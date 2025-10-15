<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource para transformar EnrollmentLog a JSON
 * Principio: Single Responsibility - Solo transforma datos de enrollment logs
 */
class EnrollmentLogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order' => [
                'stripe_payment_intent_id' => $this->stripe_payment_intent_id,
                'medusa_order_id' => $this->medusa_order_id,
            ],
            'customer' => [
                'email' => $this->customer_email,
                'name' => $this->customer_name,
            ],
            'moodle' => [
                'user_id' => $this->moodle_user_id,
                'course_id' => $this->moodle_course_id,
            ],
            'status' => $this->status,
            // Solo mostrar error si existe
            'error_message' => $this->when(
                $this->status === 'failed' && $this->error_message,
                $this->error_message
            ),
            // Webhook payload solo si es admin o en debug
            'webhook_payload' => $this->when(
                config('app.debug'),
                $this->webhook_payload
            ),
            'timestamps' => [
                'created_at' => $this->created_at?->toIso8601String(),
                'updated_at' => $this->updated_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * Get additional data that should be returned with the resource array.
     *
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                'version' => '1.0',
                'timestamp' => now()->toIso8601String(),
            ],
        ];
    }
}