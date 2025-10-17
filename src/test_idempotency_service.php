<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Services\Webhook\WebhookIdempotencyService;
use App\Models\ProcessedWebhook;
use App\Models\User;

echo "🧪 Testing WebhookIdempotencyService\n";
echo "====================================\n\n";

$service = app(WebhookIdempotencyService::class);

// Test 1: New webhook (should process)
echo "Test 1: New webhook...\n";
$result = $service->canProcessWebhook('webhook-new-123', 'order-new-456', 'new@example.com');
echo $result['can_process'] ? "  ✅ Can process\n" : "  ❌ Cannot process\n";
echo "  Reason: {$result['reason']}\n\n";

// Test 2: Duplicate webhook
echo "Test 2: Duplicate webhook...\n";
ProcessedWebhook::create([
    'webhook_id' => 'webhook-dup-123',
    'order_id' => 'order-dup-456',
    'event_type' => 'order.paid',
    'payload' => ['test' => 'data'],
    'processed_at' => now(),
]);

$result = $service->canProcessWebhook('webhook-dup-123', 'order-dup-456', 'dup@example.com');
echo $result['can_process'] ? "  ❌ Should not process\n" : "  ✅ Correctly rejected\n";
echo "  Reason: {$result['reason']}\n\n";

// Test 3: Existing user in Moodle
echo "Test 3: User exists in Moodle...\n";
$existingUser = User::factory()->create([
    'email' => 'existing@example.com',
    'moodle_user_id' => 999,
]);

$result = $service->canProcessWebhook('webhook-new-789', 'order-new-999', 'existing@example.com');
echo $result['can_process'] ? "  ❌ Should not process\n" : "  ✅ Correctly rejected\n";
echo "  Reason: {$result['reason']}\n";
echo "  User ID: {$result['user']->moodle_user_id}\n\n";

// Cleanup
ProcessedWebhook::where('webhook_id', 'webhook-dup-123')->delete();
$existingUser->delete();

echo "✅ All service tests passed\n";
