<?php

namespace App\Http\Controllers\Api;

use App\DTOs\MedusaOrderDTO;
use App\Http\Controllers\Controller;
use App\Jobs\CreateMoodleUserJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Controlador de Webhooks de Medusa
 * Principio: Single Responsibility - Solo recibe webhooks y despacha jobs
 */
class MedusaWebhookController extends Controller
{
    /**
     * Maneja el webhook de Medusa cuando se paga un pedido.
     * 
     * Ahora es ASÍNCRONO - solo valida y despacha jobs a la cola
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
                
                return response()->json([
                    'error' => 'Invalid webhook payload',
                    'message' => 'Email is required and must be valid',
                ], 400);
            }

            // Despachar job a la cola (ASÍNCRONO)
            CreateMoodleUserJob::dispatch($orderDTO);

            Log::info('📤 Job despachado a la cola', [
                'order_id' => $orderDTO->orderId,
                'email' => $orderDTO->customerEmail,
            ]);

            // Responder inmediatamente a Medusa (no esperar procesamiento)
            return response()->json([
                'message' => 'Webhook received and queued for processing',
                'order_id' => $orderDTO->orderId,
                'status' => 'queued',
            ], 202); // 202 Accepted

        } catch (\Throwable $e) {
            Log::error('❌ Error al procesar webhook', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Error processing webhook',
                'message' => 'The webhook has been received but could not be queued',
                'details' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
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