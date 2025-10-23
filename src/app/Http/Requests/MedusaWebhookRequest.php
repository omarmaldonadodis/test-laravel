<?php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class MedusaWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer.email' => 'required|email',
            'customer.first_name' => 'required|string|min:2',
            'customer.last_name' => 'required|string|min:2',
            'customer.id' => 'nullable|string',
            'items' => 'required|array|min:1',
        ];
    }

    // ✅ NUEVO: Validación adicional después de las reglas básicas
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            $orderDTO = $this->toOrderDTO();
            
            if (!$orderDTO->isValid()) {
                $validator->errors()->add(
                    'order',
                    'Invalid order data: email or order ID missing'
                );
            }
        });
    }

    // ✅ NUEVO: Método helper para convertir a DTO
    public function toOrderDTO(): \App\DTOs\MedusaOrderDTO
    {
        return \App\DTOs\MedusaOrderDTO::fromWebhookPayload(
            $this->validated()
        );
    }

    // Respuesta personalizada para errores de validación
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'error' => [
                    'message' => 'Invalid webhook payload',
                    'code' => 'VALIDATION_ERROR',
                    'status' => 422,
                    'details' => $validator->errors(),
                ],
            ], 422)
        );
    }
}
