<?php

namespace App\Services\Webhook;

use App\Models\ProcessedWebhook;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
     * Verifica si un webhook puede ser procesado
     * Retorna array con 'can_process', 'reason', 'message'
     */
    public function canProcessWebhook(string $webhookId, string $orderId, string $email): array
    {
        // Check 1: Webhook ID ya procesado
        if ($this->isWebhookProcessed($webhookId)) {
            Log::info('Webhook already processed', [
                'webhook_id' => $webhookId,
                'reason' => 'duplicate_webhook_id'
            ]);

            return [
                'can_process' => false,
                'reason' => 'duplicate_webhook',
                'message' => 'This webhook has already been processed',
            ];
        }

        // Check 2: Order ID ya procesado
        if ($this->isOrderProcessed($orderId)) {
            Log::info('Order already processed', [
                'order_id' => $orderId,
                'reason' => 'duplicate_order_id'
            ]);

            return [
                'can_process' => false,
                'reason' => 'duplicate_order',
                'message' => 'This order has already been processed',
            ];
        }

        // Check 3: Usuario ya existe en Moodle
        $existingUser = $this->findExistingMoodleUser($email);
        if ($existingUser) {
            Log::info('User already exists in Moodle', [
                'email' => $email,
                'moodle_user_id' => $existingUser->moodle_user_id,
                'reason' => 'user_exists'
            ]);

            return [
                'can_process' => false,
                'reason' => 'user_exists',
                'message' => 'User already exists in Moodle',
                'user' => $existingUser,
            ];
        }

        return [
            'can_process' => true,
            'reason' => 'new_webhook',
            'message' => 'Webhook can be processed',
        ];
    }

    /**
     * Marca un webhook como procesado
     */
    public function markAsProcessed(string $webhookId, string $orderId, array $payload): void
    {
        $this->markWebhookAsProcessed($webhookId, $orderId, $payload);
    }

    /**
     * Marca webhook como procesado (alias para compatibilidad)
     */
    public function markWebhookAsProcessed(
        string $webhookId, 
        string $orderId, 
        array $payload, 
        ?string $eventType = null
    ): ProcessedWebhook {
        return ProcessedWebhook::create([
            'webhook_id' => $webhookId,
            'event_type' => $eventType ?? 'order.paid',
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

    /**
     * Vincula orden a usuario existente
     */
    public function linkOrderToExistingUser(User $user, string $orderId): void
    {
        $user->update([
            'medusa_order_id' => $orderId,
            'moodle_processed_at' => $user->moodle_processed_at ?? now(),
        ]);
    }

    /**
     * Verifica si webhook fue procesado
     */
    private function isWebhookProcessed(string $webhookId): bool
    {
        return ProcessedWebhook::where('webhook_id', $webhookId)->exists();
    }

    /**
     * Verifica si orden fue procesada
     */
    private function isOrderProcessed(string $orderId): bool
    {
        return ProcessedWebhook::where('medusa_order_id', $orderId)
            ->where('processed_at', '>', now()->subHours(24))
            ->exists();
    }

    /**
     * Encuentra usuario existente en Moodle
     */
    private function findExistingMoodleUser(string $email): ?User
    {
        return User::where('email', $email)
            ->whereNotNull('moodle_user_id')
            ->first();
    }
}