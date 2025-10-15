<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource para respuestas de error consistentes
 */
class ErrorResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'error' => [
                'message' => $this->resource['message'] ?? 'An error occurred',
                'code' => $this->resource['code'] ?? 'GENERAL_ERROR',
                'status' => $this->resource['status'] ?? 500,
            ],
            // Detalles solo en modo debug
            'debug' => $this->when(
                config('app.debug'),
                function () {
                    return [
                        'exception' => $this->resource['exception'] ?? null,
                        'trace' => $this->resource['trace'] ?? null,
                        'file' => $this->resource['file'] ?? null,
                        'line' => $this->resource['line'] ?? null,
                    ];
                }
            ),
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Customize the response for a request.
     */
    public function withResponse(Request $request, $response): void
    {
        $status = $this->resource['status'] ?? 500;
        $response->setStatusCode($status);
    }
}