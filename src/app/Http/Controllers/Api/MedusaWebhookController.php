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
use Illuminate\Support\Str;

/**
 * Handles Medusa webhooks for order processing
 * 
 * Follows Dependency Inversion Principle by depending on WebhookIdempotencyService
 */
class MedusaWebhookController extends Controller
{
    public function __construct(
        private WebhookIdempotencyService $idempotencyService
    ) {}

    /**
     * Handle order paid webhook from Medusa
     */
    public function handleOrderPaid(MedusaWebhookRequest $request): JsonResponse
    {
        try {
            $payload = $request->validatedForDTO();
            $webhookId = $this->extractWebhookId($payload);
            
            Log::info('✅ Webhook recibido desde Medusa', [
                'webhook_id' => $webhookId,
                'ip' => $request->ip(),
            ]);

            // Create DTO from validated payload
            $orderDTO = MedusaOrderDTO::fromWebhookPayload($payload);

            // Validate minimum required data
            if (!$orderDTO->isValid()) {
                Log::warning('⚠️ Payload inválido del webhook', [
                    'webhook_id' => $webhookId,
                    'order_id' => $orderDTO->orderId,
                    'email' => $orderDTO->customerEmail,
                ]);
                
                return (new ErrorResource([
                    'message' => 'Invalid webhook payload: Email is required and must be valid',
                    'code' => 'INVALID_PAYLOAD',
                    'status' => 400,
                ]))->response();
            }

            // ✅ Check idempotency using service (SRP + DIP)
            $idempotencyCheck = $this->idempotencyService->canProcessWebhook(
                $webhookId,
                $orderDTO->orderId,
                $orderDTO->customerEmail
            );

            // Handle duplicate cases
            if (!$idempotencyCheck['can_process']) {
                return $this->handleDuplicateWebhook(
                    $idempotencyCheck,
                    $webhookId,
                    $orderDTO,
                    $payload
                );
            }

            // ✅ Process new webhook
            $this->idempotencyService->markWebhookAsProcessed(
                $webhookId,
                $orderDTO->orderId,
                $payload,
                $payload['type'] ?? 'order.paid'
            );

            // Dispatch job to queue
            CreateMoodleUserJob::dispatch($orderDTO);

            Log::info('📤 Job despachado a la cola', [
                'webhook_id' => $webhookId,
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
     * Handle duplicate webhook scenarios
     */
    private function handleDuplicateWebhook(
        array $idempotencyCheck,
        string $webhookId,
        MedusaOrderDTO $orderDTO,
        array $payload
    ): JsonResponse {
        $reason = $idempotencyCheck['reason'];

        Log::info("🔄 Webhook duplicado: {$reason}", [
            'webhook_id' => $webhookId,
            'order_id' => $orderDTO->orderId,
            'email' => $orderDTO->customerEmail,
        ]);

        // If user exists, link this order to them
        if ($reason === 'user_exists' && isset($idempotencyCheck['user'])) {
            $this->idempotencyService->linkOrderToExistingUser(
                $idempotencyCheck['user'],
                $orderDTO->orderId
            );

            // Still mark this webhook as processed
            $this->idempotencyService->markWebhookAsProcessed(
                $webhookId,
                $orderDTO->orderId,
                $payload
            );

            return (new WebhookResponseResource([
                'message' => $idempotencyCheck['message'],
                'status' => $reason,
                'order_id' => $orderDTO->orderId,
                'customer_email' => $orderDTO->customerEmail,
                'moodle_user_id' => $idempotencyCheck['user']->moodle_user_id,
            ]))->response()->setStatusCode(200);
        }

        // For other duplicate cases, just return success
        return (new WebhookResponseResource([
            'message' => $idempotencyCheck['message'],
            'status' => $reason,
            'order_id' => $orderDTO->orderId,
            'customer_email' => $orderDTO->customerEmail,
        ]))->response()->setStatusCode(200);
    }

    /**
     * Extract or generate webhook ID
     */
    private function extractWebhookId(array $payload): string
    {
        if (isset($payload['id'])) {
            return $payload['id'];
        }

        $orderId = $payload['order']['id'] ?? $payload['data']['id'] ?? 'unknown';
        $timestamp = now()->timestamp;
        
        return 'webhook_' . $orderId . '_' . $timestamp . '_' . Str::random(8);
    }

    /**
     * Health check endpoint
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