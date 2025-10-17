<?php

namespace App\Services\Webhook;

use App\Models\ProcessedWebhook;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Service responsible for webhook idempotency checks
 * Following Single Responsibility Principle
 */
class WebhookIdempotencyService
{
    /**
     * Check if webhook can be processed (not duplicate)
     * 
     * Returns array with status and reason
     */
    public function canProcessWebhook(string $webhookId, string $orderId, string $email): array
    {
        // Check 1: Webhook ID already processed
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

        // Check 2: Order ID already processed
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

        // Check 3: User already exists in Moodle
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
     * Mark webhook as processed
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
            'payload' => $payload,
            'processed_at' => now(),
        ]);
    }

    /**
     * Update existing user with order information
     */
    public function linkOrderToExistingUser(User $user, string $orderId): void
    {
        $user->update([
            'medusa_order_id' => $orderId,
            'moodle_processed_at' => $user->moodle_processed_at ?? now(),
        ]);
    }

    /**
     * Check if webhook was already processed
     */
    private function isWebhookProcessed(string $webhookId): bool
    {
        return ProcessedWebhook::where('webhook_id', $webhookId)->exists();
    }

    /**
     * Check if order was already processed
     */
    private function isOrderProcessed(string $orderId): bool
    {
        return ProcessedWebhook::where('medusa_order_id', $orderId)
            ->where('processed_at', '>', now()->subHours(24))
            ->exists();
    }

    /**
     * Find user that already exists in Moodle
     */
    private function findExistingMoodleUser(string $email): ?User
    {
        return User::where('email', $email)
            ->whereNotNull('moodle_user_id')
            ->first();
    }
}
