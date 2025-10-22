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

use OpenApi\Annotations as OA;

/**
 * @OA\Info(
 *     title="Laravel Webhooks API",
 *     version="1.0.0",
 *     description="API para integración de webhooks entre Medusa y Moodle",
 *     @OA\Contact(
 *         email="support@example.com"
 *     )
 * )
 * 
 * @OA\Server(
 *     url="http://localhost:8080",
 *     description="Development Server"
 * )
 * 
 * @OA\Server(
 *     url="https://api.production.com",
 *     description="Production Server"
 * )
 * 
 * @OA\SecurityScheme(
 *     securityScheme="webhook_signature",
 *     type="apiKey",
 *     in="header",
 *     name="X-Medusa-Signature"
 * )
 */
class MedusaWebhookController extends Controller
{
    public function __construct(
        private WebhookIdempotencyService $idempotencyService
    ) {}

    /**
     * @OA\Post(
     *     path="/api/webhooks/medusa/order-paid",
     *     operationId="handleOrderPaid",
     *     tags={"Webhooks"},
     *     summary="Procesa webhook de orden pagada desde Medusa",
     *     description="Recibe webhook cuando se completa el pago de una orden en Medusa y crea/inscribe usuario en Moodle",
     *     security={{"webhook_signature":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Payload del webhook de Medusa",
     *         @OA\JsonContent(
     *             required={"customer", "items"},
     *             @OA\Property(property="id", type="string", example="order_01HXYZ123", description="ID de la orden (opcional)"),
     *             @OA\Property(
     *                 property="customer",
     *                 type="object",
     *                 required={"email", "first_name", "last_name", "id"},
     *                 @OA\Property(property="email", type="string", format="email", example="customer@example.com"),
     *                 @OA\Property(property="first_name", type="string", example="John"),
     *                 @OA\Property(property="last_name", type="string", example="Doe"),
     *                 @OA\Property(property="id", type="string", example="cus_01HXYZ123")
     *             ),
     *             @OA\Property(
     *                 property="items",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="string", example="item_01HXYZ123"),
     *                     @OA\Property(property="title", type="string", example="Curso de Laravel"),
     *                     @OA\Property(property="product_id", type="string", example="prod_01HXYZ123"),
     *                     @OA\Property(property="variant_id", type="string", example="variant_01HXYZ123"),
     *                     @OA\Property(property="quantity", type="integer", example=1),
     *                     @OA\Property(property="unit_price", type="number", format="float", example=49.99),
     *                     @OA\Property(property="total", type="number", format="float", example=49.99),
     *                     @OA\Property(
     *                         property="metadata",
     *                         type="object",
     *                         @OA\Property(property="moodle_course_id", type="integer", example=2)
     *                     )
     *                 )
     *             ),
     *             @OA\Property(
     *                 property="metadata",
     *                 type="object",
     *                 example={"custom_field": "value"}
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=202,
     *         description="Webhook recibido y procesado exitosamente",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Webhook received and queued for processing"),
     *             @OA\Property(property="status", type="string", example="queued"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="order_id", type="string", example="order_01HXYZ123"),
     *                 @OA\Property(property="customer_email", type="string", example="customer@example.com"),
     *                 @OA\Property(property="queued_at", type="string", format="date-time", example="2025-10-21T10:30:00Z")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Webhook duplicado (ya procesado)",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Webhook already processed"),
     *             @OA\Property(property="status", type="string", example="duplicate"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="order_id", type="string"),
     *                 @OA\Property(property="customer_email", type="string")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Payload inválido",
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="error",
     *                 type="object",
     *                 @OA\Property(property="message", type="string", example="Invalid webhook payload"),
     *                 @OA\Property(property="code", type="string", example="INVALID_PAYLOAD"),
     *                 @OA\Property(property="status", type="integer", example=400)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Firma del webhook inválida",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="Invalid signature")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Error interno del servidor",
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="error",
     *                 type="object",
     *                 @OA\Property(property="message", type="string", example="Error processing webhook"),
     *                 @OA\Property(property="code", type="string", example="WEBHOOK_ERROR"),
     *                 @OA\Property(property="status", type="integer", example=500)
     *             )
     *         )
     *     )
     * )
     */
    public function handleOrderPaid(MedusaWebhookRequest $request): JsonResponse
    {
        // ✅ INICIO DE MÉTRICAS
        $startTime = microtime(true);
        $webhookId = $request->header('X-Webhook-Id') ?? uniqid('wh_');

        try {
            Log::debug('🔍 Payload RAW', [
                'validated' => $request->validated(),
                'validatedForDTO' => $request->validatedForDTO(),
            ]);

            $orderDTO = MedusaOrderDTO::fromWebhookPayload($request->validatedForDTO());
            $orderId = $orderDTO->orderId;

            Log::info('📥 Webhook recibido', [
                'webhook_id' => $webhookId,
                'order_id' => $orderId,
                'email' => $orderDTO->customerEmail,
            ]);

            // Validar datos
            if (!$orderDTO->isValid()) {
                $duration = round((microtime(true) - $startTime) * 1000, 2);

                Log::warning('⚠️ Payload inválido del webhook', [
                    'webhook_id' => $webhookId,
                    'order_id' => $orderId,
                    'email' => $orderDTO->customerEmail,
                    'duration_ms' => $duration,
                ]);

                return (new ErrorResource([
                    'message' => 'Invalid webhook payload: Email is required and must be valid',
                    'code' => 'INVALID_PAYLOAD',
                    'status' => 400,
                ]))->response();
            }

            // Verificar idempotencia
            $payload = $request->validated();

            if (!$this->idempotencyService->checkAndMark($webhookId, $orderId, $payload)) {
                $duration = round((microtime(true) - $startTime) * 1000, 2);

                Log::info('⚠️ Webhook duplicado ignorado', [
                    'webhook_id' => $webhookId,
                    'order_id' => $orderId,
                    'email' => $orderDTO->customerEmail,
                    'duration_ms' => $duration,
                ]);

                return (new WebhookResponseResource([
                    'message' => 'Webhook already processed',
                    'status' => 'duplicate',
                    'order_id' => $orderId,
                    'customer_email' => $orderDTO->customerEmail,
                ]))->response()->setStatusCode(200);
            }

            // Despachar Job
            CreateMoodleUserJob::dispatch($orderDTO);

            // ✅ MÉTRICAS FINALES
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Log::info('📤 Job despachado a la cola', [
                'webhook_id' => $webhookId,
                'order_id' => $orderId,
                'email' => $orderDTO->customerEmail,
                'duration_ms' => $duration,
                'queue' => 'default',
            ]);

            Log::info('📊 Webhook metrics', [
                'webhook_id' => $webhookId,
                'processing_time_ms' => $duration,
                'items_count' => count($orderDTO->items),
                'courses_count' => count($orderDTO->getCourseIds()),
                'status' => 'queued',
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
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Log::error('❌ Error al procesar webhook', [
                'webhook_id' => $webhookId ?? 'unknown',
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'duration_ms' => $duration,
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
     * @OA\Get(
     *     path="/api/webhooks/medusa/health",
     *     operationId="healthCheck",
     *     tags={"Health"},
     *     summary="Verifica el estado del servicio de webhooks",
     *     @OA\Response(
     *         response=200,
     *         description="Servicio funcionando correctamente",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="ok"),
     *             @OA\Property(property="service", type="string", example="webhook-handler"),
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
}
