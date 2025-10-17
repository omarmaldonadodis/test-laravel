<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Services\Webhook\WebhookIdempotencyService;
use App\Models\ProcessedWebhook;
use App\Models\User;

echo "🧪 Integration Test: Idempotency Flow\n";
echo "=====================================\n\n";

// Limpiar datos previos
echo "🧹 Cleaning up previous test data...\n";
User::where('email', 'LIKE', '%@test-idempotency.com')->delete();
ProcessedWebhook::whereIn('medusa_order_id', ['ord-001', 'ord-002', 'ord-003'])->delete();
echo "  ✅ Cleanup complete\n\n";

$service = app(WebhookIdempotencyService::class);

// Scenario 1: First webhook arrives
echo "📥 Scenario 1: First webhook arrives\n";
$email1 = 'customer-' . uniqid() . '@test-idempotency.com';
$check1 = $service->canProcessWebhook('wh-001', 'ord-001', $email1);

if ($check1['can_process']) {
    echo "  ✅ First webhook: Can process\n";
    echo "  Reason: {$check1['reason']}\n";
    
    $service->markWebhookAsProcessed('wh-001', 'ord-001', ['data' => 'test']);
    echo "  ✅ Marked as processed\n";
} else {
    echo "  ❌ Failed to process first webhook\n";
    echo "  Reason: {$check1['reason']}\n";
    echo "  Message: {$check1['message']}\n";
}

// Scenario 2: Same webhook arrives again (Medusa retry)
echo "\n📥 Scenario 2: Same webhook arrives again (retry)\n";
$check2 = $service->canProcessWebhook('wh-001', 'ord-001', $email1);

if (!$check2['can_process'] && $check2['reason'] === 'duplicate_webhook') {
    echo "  ✅ Duplicate webhook detected and rejected\n";
    echo "  Message: {$check2['message']}\n";
} else {
    echo "  ❌ Should have rejected duplicate webhook\n";
    echo "  Reason: {$check2['reason']}\n";
}

// Scenario 3: Different webhook, same order
echo "\n📥 Scenario 3: Different webhook ID, same order\n";
$check3 = $service->canProcessWebhook('wh-002', 'ord-001', $email1);

if (!$check3['can_process'] && $check3['reason'] === 'duplicate_order') {
    echo "  ✅ Duplicate order detected and rejected\n";
    echo "  Message: {$check3['message']}\n";
} else {
    echo "  ❌ Should have rejected duplicate order\n";
    echo "  Reason: {$check3['reason']}\n";
}

// Scenario 4: User already exists in Moodle
echo "\n📥 Scenario 4: User already exists in Moodle\n";
$email2 = 'existing-' . uniqid() . '@test-idempotency.com';

// Primero verificar que NO existe
$existingCheck = User::where('email', $email2)->first();
if ($existingCheck) {
    echo "  ⚠️  Cleaning up existing user...\n";
    $existingCheck->delete();
}

// Ahora crear el usuario
$user = User::factory()->create([
    'email' => $email2,
    'moodle_user_id' => 123,
]);
echo "  Created test user: {$user->email}\n";

$check4 = $service->canProcessWebhook('wh-003', 'ord-002', $email2);

if (!$check4['can_process'] && $check4['reason'] === 'user_exists') {
    echo "  ✅ Existing Moodle user detected\n";
    echo "  Message: {$check4['message']}\n";
    echo "  Moodle User ID: {$check4['user']->moodle_user_id}\n";
    
    // Link new order to existing user
    $service->linkOrderToExistingUser($user, 'ord-002');
    $user->refresh();
    
    if ($user->medusa_order_id === 'ord-002') {
        echo "  ✅ Order linked to existing user\n";
        echo "  User order ID: {$user->medusa_order_id}\n";
    } else {
        echo "  ❌ Failed to link order\n";
    }
} else {
    echo "  ❌ Should have detected existing user\n";
    echo "  Reason: {$check4['reason']}\n";
}

// Scenario 5: Completely new webhook and order
echo "\n📥 Scenario 5: Brand new webhook and order\n";
$email3 = 'newcustomer-' . uniqid() . '@test-idempotency.com';
$check5 = $service->canProcessWebhook('wh-004', 'ord-003', $email3);

if ($check5['can_process'] && $check5['reason'] === 'new_webhook') {
    echo "  ✅ New webhook can be processed\n";
    echo "  Message: {$check5['message']}\n";
} else {
    echo "  ❌ Should have allowed new webhook\n";
    echo "  Reason: {$check5['reason']}\n";
}

// Final cleanup
echo "\n🧹 Final cleanup...\n";
ProcessedWebhook::whereIn('medusa_order_id', ['ord-001', 'ord-002', 'ord-003'])->delete();
User::where('email', 'LIKE', '%@test-idempotency.com')->delete();
echo "  ✅ All test data cleaned\n";

echo "\n🎉 All integration scenarios completed\n";
