<?php

namespace App\Http\Controllers\Api;

use App\DTOs\MedusaOrderDTO;
use App\Http\Controllers\Controller;
use App\Http\Resources\WebhookResponseResource;
use App\Http\Resources\ErrorResource;
use App\Jobs\CreateMoodleUserJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MedusaWebhookController extends Controller
{
    /**
     * Maneja el webhook de Medusa cuando se paga un pedido.
     * 
     * Ahora usa API Resources para respuestas consistentes
     */
    public function handleOrderPaid(Request $request): JsonResponse
    {
        try {
            Log::info('✅ Webhook recibido desde Medusa', [
                'payload' => $request->all(),
                'ip' => $request->ip(),
            ]);

            // Crear DTO desde payload
            $orderDTO = MedusaOrderDTO::fromWebhookPayload($request->all());

            // Validar datos mínimos
            if (!$orderDTO->isValid()) {
                Log::warning('⚠️ Payload inválido del webhook', [
                    'order_id' => $orderDTO->orderId,
                    'email' => $orderDTO->customerEmail,
                ]);
                
                // ✅ Usar ErrorResource
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

            // Usar WebhookResponseResource
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

            // Usar ErrorResource
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