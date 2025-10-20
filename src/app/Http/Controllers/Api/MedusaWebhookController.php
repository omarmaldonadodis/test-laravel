<?php

namespace App\Http\Controllers\Api;

use App\DTOs\MedusaOrderDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\MedusaWebhookRequest;
use App\Http\Resources\WebhookResponseResource;
use App\Http\Resources\ErrorResource;
use App\Jobs\CreateMoodleUserJob;
use App\Services\Webhook\WebhookIdempotencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class MedusaWebhookController extends Controller
{
    public function __construct(
        private WebhookIdempotencyService $idempotencyService
    ) {}

    public function handleOrderPaid(MedusaWebhookRequest $request): JsonResponse
    {
        try {

            Log::debug('🔍 Payload RAW', [
                'validated' => $request->validated(),
                'validatedForDTO' => $request->validatedForDTO(),
            ]);
            
            $orderDTO = MedusaOrderDTO::fromWebhookPayload($request->validatedForDTO());
            
            // 🔍 DEBUG: Ver el DTO creado
            Log::debug('🔍 DTO Creado', [
                'orderId' => $orderDTO->orderId,
                'email' => $orderDTO->customerEmail,
                'isValid' => $orderDTO->isValid(),
            ]);
        

            // Generar webhook ID único
            $webhookId = $request->header('X-Webhook-Id') ?? $orderDTO->orderId ?? uniqid('wh_');
            $orderId = $orderDTO->orderId;
            
            Log::info('📥 Webhook recibido', [
                'webhook_id' => $webhookId,
                'order_id' => $orderId,
                'email' => $orderDTO->customerEmail,
            ]);

            // ✅ VALIDAR DATOS ANTES DE VERIFICAR IDEMPOTENCIA
            if (!$orderDTO->isValid()) {
                Log::warning('⚠️ Payload inválido del webhook', [
                    'webhook_id' => $webhookId,
                    'order_id' => $orderId,
                    'email' => $orderDTO->customerEmail,
                ]);
                
                return (new ErrorResource([
                    'message' => 'Invalid webhook payload: Email is required and must be valid',
                    'code' => 'INVALID_PAYLOAD',
                    'status' => 400,
                ]))->response();
            }

            // ✅ VERIFICACIÓN DE IDEMPOTENCIA (con el payload completo para guardar)
            $payload = $request->validated();
            
            if (!$this->idempotencyService->checkAndMark($webhookId, $orderId, $payload)) {
                Log::info('⚠️ Webhook duplicado ignorado', [
                    'webhook_id' => $webhookId,
                    'order_id' => $orderId,
                    'email' => $orderDTO->customerEmail,
                ]);

                return (new WebhookResponseResource([
                    'message' => 'Webhook already processed',
                    'status' => 'duplicate',
                    'order_id' => $orderId,
                    'customer_email' => $orderDTO->customerEmail,
                ]))->response()->setStatusCode(200); // 200 para evitar reintentos
            }

            // ✅ DESPACHAR JOB (usando el DTO que ya tenemos)
            CreateMoodleUserJob::dispatch($orderDTO);

            Log::info('📤 Job despachado a la cola', [
                'webhook_id' => $webhookId,
                'order_id' => $orderId,
                'email' => $orderDTO->customerEmail,
            ]);

            return (new WebhookResponseResource([
                'message' => 'Webhook received and queued for processing',
                'status' => 'queued',
                'order_id' => $orderId,
                'customer_email' => $orderDTO->customerEmail,
                'queued_at' => now(),
                'job_class' => CreateMoodleUserJob::class,
            ]))->response();

        } catch (\Throwable $e) {
            Log::error('❌ Error al procesar webhook', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
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