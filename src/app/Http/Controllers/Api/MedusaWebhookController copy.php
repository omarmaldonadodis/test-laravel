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
            $payload = $request->validated();
            
            // Generar webhook ID único (Medusa debe enviarlo en headers o payload)
            $webhookId = $request->header('X-Webhook-Id') ?? $payload['id'] ?? uniqid('wh_');
            // Crear DTO desde payload validado
            $orderDTO = MedusaOrderDTO::fromWebhookPayload($request->validatedForDTO());
            $orderId = $orderDTO->orderId;
            
            Log::info('Webhook recibido', [
                'webhook_id' => $webhookId,
                'order_id' => $orderId,
            ]);

            // ✅ VERIFICACIÓN DE IDEMPOTENCIA
            if (!$this->idempotencyService->checkAndMark($webhookId, $orderId, $payload)) {
                Log::info('⚠️ Webhook duplicado ignorado', [
                    'webhook_id' => $webhookId,
                    'order_id' => $orderId,
                ]);

                return (new WebhookResponseResource([
                    'message' => 'Webhook already processed',
                    'status' => 'duplicate',
                    'order_id' => $orderId,
                ]))->response()->setStatusCode(200); // 200 para evitar reintentos
            }

            // Crear DTO y despachar job
            $orderDTO = MedusaOrderDTO::fromWebhookPayload($payload);

            if (!$orderDTO->isValid()) {
                Log::warning('⚠️ Payload inválido', ['order_id' => $orderId]);
                
                return (new ErrorResource([
                    'message' => 'Invalid webhook payload',
                    'code' => 'INVALID_PAYLOAD',
                    'status' => 400,
                ]))->response();
            }

            CreateMoodleUserJob::dispatch($orderDTO);

            Log::info('📤 Job despachado', ['order_id' => $orderId]);

            return (new WebhookResponseResource([
                'message' => 'Webhook received and queued',
                'status' => 'queued',
                'order_id' => $orderId,
                'queued_at' => now(),
            ]))->response();

        } catch (\Throwable $e) {
            Log::error('❌ Error procesando webhook', [
                'error' => $e->getMessage(),
            ]);

            return (new ErrorResource([
                'message' => 'Error processing webhook',
                'code' => 'WEBHOOK_ERROR',
                'status' => 500,
            ]))->response();
        }
    }

    public function healthCheck(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'webhook-handler',
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}

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