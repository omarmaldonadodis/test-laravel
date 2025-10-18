<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MedusaWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        // La autorización se maneja en el middleware VerifyWebhookSignature
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => 'nullable|string',
            'type' => 'nullable|string',
            'data' => 'nullable|array',
            'order' => 'nullable|array',
            
            // Validaciones flexibles para ambos formatos (data.* o order.*)
            'data.id' => 'nullable|string',
            'data.email' => 'nullable|email',
            'data.customer_id' => 'nullable|string',
            'data.items' => 'nullable|array',
            
            'order.id' => 'nullable|string',
            'order.email' => 'nullable|email',
            'order.customer_id' => 'nullable|string',
            'order.items' => 'nullable|array',
            
        ];
    }

    public function messages(): array
    {
        return [
            'data.email.email' => 'Customer email must be valid',
            'order.email.email' => 'Order email must be valid',
        ];
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        \Illuminate\Support\Facades\Log::warning('Medusa webhook validation failed', [
            'errors' => $validator->errors()->toArray(),
            'payload_keys' => array_keys($this->all()),
        ]);

        parent::failedValidation($validator);
    }

    /**
     * Get validated data ready for DTO
     */
    public function validatedForDTO(): array
    {
        return $this->validated();
    }
}