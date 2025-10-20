<?php

namespace App\Services\Webhook;

use App\Models\ProcessedWebhook;
use Illuminate\Support\Facades\DB;

/**
 * Servicio de idempotencia para webhooks
 * Principio: Single Responsibility - Solo maneja lógica de duplicados
 */
class WebhookIdempotencyService
{
    /**
     * Verifica si un webhook ya fue procesado
     */
    public function isProcessed(string $webhookId, string $orderId): bool
    {
        return ProcessedWebhook::where('webhook_id', $webhookId)
            ->orWhere('medusa_order_id', $orderId)
            ->exists();
    }

    /**
     * Marca un webhook como procesado
     */
    public function markAsProcessed(string $webhookId, string $orderId, array $payload): void
    {
        ProcessedWebhook::create([
            'webhook_id' => $webhookId,
            'event_type' => $payload['type'] ?? 'order.paid',
            'medusa_order_id' => $orderId,
            'user_email' => $payload['customer']['email'] ?? null,
            'payload' => $payload,
            'processed_at' => now(),
        ]);
    }

    /**
     * Verifica y marca en una transacción atómica
     */
    public function checkAndMark(string $webhookId, string $orderId, array $payload): bool
    {
        return DB::transaction(function () use ($webhookId, $orderId, $payload) {
            if ($this->isProcessed($webhookId, $orderId)) {
                return false; // Ya procesado
            }

            $this->markAsProcessed($webhookId, $orderId, $payload);
            return true; // Procesado exitosamente
        });
    }
}