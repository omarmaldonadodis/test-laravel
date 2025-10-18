<?php

namespace App\Http\Controllers\Api;

use App\DTOs\MedusaOrderDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\MedusaWebhookRequest;
use App\Http\Resources\WebhookResponseResource;
use App\Http\Resources\ErrorResource;
use App\Jobs\CreateMoodleUserJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class MedusaWebhookController extends Controller
{
    /**
     * Maneja el webhook de Medusa cuando se paga un pedido.
     * 
     * Ahora usa FormRequest para validación y API Resources para respuestas
     */
    public function handleOrderPaid(MedusaWebhookRequest $request): JsonResponse
    {
        try {
            Log::info('Webhook recibido desde Medusa', [
                'payload' => $request->validated(),
                'ip' => $request->ip(),
            ]);

            // Crear DTO desde payload validado
            $orderDTO = MedusaOrderDTO::fromWebhookPayload($request->validatedForDTO());

            // Validar datos mínimos
            if (!$orderDTO->isValid()) {
                Log::warning('⚠️ Payload inválido del webhook', [
                    'order_id' => $orderDTO->orderId,
                    'email' => $orderDTO->customerEmail,
                ]);
                
                return (new ErrorResource([
                    'message' => 'Invalid webhook payload: Email is required and must be valid',
                    'code' => 'INVALID_PAYLOAD',
                    'status' => 400,
                ]))->response();
            }

            // Despachar job a la cola (ASÍNCRONO)
            CreateMoodleUserJob::dispatch($orderDTO);

            Log::info('📤 Job despachado a la cola', [
                'order_id' => $orderDTO->orderId,
                'email' => $orderDTO->customerEmail,
            ]);

            return (new WebhookResponseResource([
                'message' => 'Webhook received and queued for processing',
                'status' => 'queued',
                'order_id' => $orderDTO->orderId,
                'customer_email' => $orderDTO->customerEmail,
                'queued_at' => now(),
                'job_class' => CreateMoodleUserJob::class,
            ]))->response();

        } catch (\Throwable $e) {
            Log::error('❌ Error al procesar webhook', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return (new ErrorResource([
                'message' => 'Error processing webhook',
                'code' => 'WEBHOOK_ERROR',
                'status' => 500,
                'exception' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ]))->response();
        }
    }

    /**
     * Endpoint de health check para webhooks
     */
    public function healthCheck(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'webhook-handler',
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}