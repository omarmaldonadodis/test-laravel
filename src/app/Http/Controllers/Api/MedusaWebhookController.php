<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MedusaWebhookRequest;
use App\Http\Resources\{WebhookResponseResource, ErrorResource};
use App\Jobs\CreateMoodleUserJob;
use App\Services\Webhook\WebhookIdempotencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use OpenApi\Annotations as OA;

/**
 * @OA\Info(
 *     title="Laravel Webhooks API - Moodle & Medusa",
 *     version="1.0.0",
 *     description="API para integración automática de webhooks entre Medusa (e-commerce) y Moodle (LMS)",
 *     @OA\Contact(email="support@example.com")
 * )
 * 
 * @OA\Server(url="http://localhost:8080", description="Development")
 * @OA\Server(url="https://api.production.com", description="Production")
 * 
 * @OA\SecurityScheme(
 *     securityScheme="webhook_signature",
 *     type="apiKey",
 *     in="header",
 *     name="X-Medusa-Signature",
 *     description="HMAC SHA256 signature del payload usando MEDUSA_WEBHOOK_SECRET"
 * )
 * 
 * @OA\Tag(name="Webhooks", description="Endpoints para recibir eventos de Medusa")
 * @OA\Tag(name="Health", description="Health checks y monitoring")
 */
class MedusaWebhookController extends Controller
{
    public function __construct(
        private WebhookIdempotencyService $idempotencyService
    ) {}

    /**
     * Procesa webhook de orden pagada
     * 
     * @OA\Post(
     *     path="/api/webhooks/medusa/order-paid",
     *     operationId="handleOrderPaid",
     *     tags={"Webhooks"},
     *     summary="Procesa orden pagada de Medusa",
     *     description="Recibe notificación cuando se completa pago, crea usuario en Moodle y lo inscribe en cursos",
     *     security={{"webhook_signature":{}}},
     *     
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"customer", "items"},
     *             @OA\Property(property="id", type="string", example="order_01HXYZ123"),
     *             @OA\Property(
     *                 property="customer",
     *                 required={"email", "first_name", "last_name"},
     *                 @OA\Property(property="email", type="string", format="email"),
     *                 @OA\Property(property="first_name", type="string"),
     *                 @OA\Property(property="last_name", type="string")
     *             ),
     *             @OA\Property(
     *                 property="items",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="string"),
     *                     @OA\Property(
     *                         property="metadata",
     *                         @OA\Property(property="moodle_course_id", type="integer", example=2)
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     
     *     @OA\Response(response=202, description="Webhook aceptado y encolado",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="queued"),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Webhook duplicado (ya procesado)"),
     *     @OA\Response(response=401, description="Firma inválida"),
     *     @OA\Response(response=422, description="Datos inválidos")
     * )
     */
    public function handleOrderPaid(MedusaWebhookRequest $request): JsonResponse
    {
        $startTime = microtime(true);
        $webhookId = $request->header('X-Webhook-Id') ?? uniqid('wh_');

        try {
            // Ya validado por FormRequest
            $orderDTO = $request->toOrderDTO();

            Log::info('📥 Webhook recibido', [
                'webhook_id' => $webhookId,
                'order_id' => $orderDTO->orderId,
                'email' => $orderDTO->customerEmail,
            ]);

            // Idempotencia
            if (!$this->idempotencyService->checkAndMark(
                $webhookId,
                $orderDTO->orderId,
                $request->validated()
            )) {
                return $this->duplicateResponse($orderDTO, $startTime);
            }

            // Despachar job
            CreateMoodleUserJob::dispatch($orderDTO);

            return $this->successResponse($orderDTO, $startTime);

        } catch (\Throwable $e) {
            return $this->errorResponse($e, $startTime, $webhookId);
        }
    }

    /**
     * Health check
     * 
     * @OA\Get(
     *     path="/api/webhooks/medusa/health",
     *     operationId="healthCheck",
     *     tags={"Health"},
     *     summary="Verifica estado del servicio",
     *     
     *     @OA\Response(response=200, description="Servicio operativo",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="ok"),
     *             @OA\Property(property="service", type="string"),
     *             @OA\Property(property="timestamp", type="string", format="date-time")
     *         )
     *     )
     * )
     */
    public function healthCheck(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'webhook-handler',
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    // ========================================
    // Métodos privados (DRY)
    // ========================================

    private function duplicateResponse($orderDTO, $startTime): JsonResponse
    {
        $this->logMetrics('duplicate', $orderDTO, $startTime);

        return (new WebhookResponseResource([
            'message' => 'Webhook already processed',
            'status' => 'duplicate',
            'order_id' => $orderDTO->orderId,
            'customer_email' => $orderDTO->customerEmail,
        ]))->response()->setStatusCode(200);
    }

    private function successResponse($orderDTO, $startTime): JsonResponse
    {
        $this->logMetrics('queued', $orderDTO, $startTime);

        return (new WebhookResponseResource([
            'message' => 'Webhook received and queued for processing',
            'status' => 'queued',
            'order_id' => $orderDTO->orderId,
            'customer_email' => $orderDTO->customerEmail,
            'queued_at' => now(),
        ]))->response();
    }

    private function errorResponse(\Throwable $e, $startTime, $webhookId): JsonResponse
    {
        $duration = round((microtime(true) - $startTime) * 1000, 2);

        Log::error('❌ Error al procesar webhook', [
            'webhook_id' => $webhookId,
            'error' => $e->getMessage(),
            'duration_ms' => $duration,
            'trace' => config('app.debug') ? $e->getTraceAsString() : null,
        ]);

        return (new ErrorResource([
            'message' => 'Error processing webhook',
            'code' => 'WEBHOOK_ERROR',
            'status' => 500,
        ]))->response();
    }

    private function logMetrics(string $status, $orderDTO, $startTime): void
    {
        $duration = round((microtime(true) - $startTime) * 1000, 2);

        Log::info('📊 Webhook metrics', [
            'status' => $status,
            'order_id' => $orderDTO->orderId,
            'email' => $orderDTO->customerEmail,
            'duration_ms' => $duration,
            'items_count' => count($orderDTO->items),
            'courses_count' => count($orderDTO->getCourseIds()),
        ]);
    }
}