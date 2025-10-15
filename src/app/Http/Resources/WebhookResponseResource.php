<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource para respuestas de webhooks
 * Se usa cuando el webhook es recibido y procesado
 */
class WebhookResponseResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'message' => $this->resource['message'] ?? 'Webhook processed',
            'status' => $this->resource['status'] ?? 'queued',
            'data' => [
                'order_id' => $this->resource['order_id'] ?? null,
                'customer_email' => $this->resource['customer_email'] ?? null,
                'queued_at' => $this->resource['queued_at'] ?? now()->toIso8601String(),
            ],
            // Información de debug solo si está habilitado
            'debug' => $this->when(
                config('app.debug'),
                function () {
                    return [
                        'job_class' => $this->resource['job_class'] ?? null,
                        'queue' => $this->resource['queue'] ?? 'default',
                    ];
                }
            ),
        ];
    }

    /**
     * Customize the response for a request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Http\Response  $response
     * @return void
     */
    public function withResponse(Request $request, $response): void
    {
        // Establecer status code 202 Accepted para webhooks
        $response->setStatusCode(202);
    }

    /**
     * Get additional data that should be returned with the resource array.
     *
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                'version' => '1.0',
                'service' => 'webhook-handler',
            ],
        ];
    }
}