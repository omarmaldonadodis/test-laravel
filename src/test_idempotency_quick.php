<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Services\Webhook\WebhookIdempotencyService;
use App\Models\ProcessedWebhook;
use App\Models\User;

echo "⚡ Quick Idempotency Test\n";
echo "========================\n\n";

$service = app(WebhookIdempotencyService::class);
$testId = uniqid();
$results = [];

try {
    // Test 1: Nueva orden puede procesarse
    echo "1. Testing new webhook... ";
    $result1 = $service->canProcessWebhook(
        "wh-{$testId}-1",
        "ord-{$testId}-1",
        "test{$testId}@example.com"
    );
    $results['new_webhook'] = $result1['can_process'] && $result1['reason'] === 'new_webhook';
    echo ($results['new_webhook'] ? "✅" : "❌") . "\n";

    // Test 2: Marcar como procesado
    echo "2. Marking webhook as processed... ";
    $service->markWebhookAsProcessed(
        "wh-{$testId}-1",
        "ord-{$testId}-1",
        ['test' => 'data']
    );
    echo "✅\n";

    // Test 3: Webhook duplicado es rechazado
    echo "3. Testing duplicate webhook... ";
    $result2 = $service->canProcessWebhook(
        "wh-{$testId}-1",
        "ord-{$testId}-1",
        "test{$testId}@example.com"
    );
    $results['duplicate_webhook'] = !$result2['can_process'] && $result2['reason'] === 'duplicate_webhook';
    echo ($results['duplicate_webhook'] ? "✅" : "❌") . "\n";

    // Test 4: Orden duplicada es rechazada
    echo "4. Testing duplicate order... ";
    $result3 = $service->canProcessWebhook(
        "wh-{$testId}-2",
        "ord-{$testId}-1",
        "test{$testId}@example.com"
    );
    $results['duplicate_order'] = !$result3['can_process'] && $result3['reason'] === 'duplicate_order';
    echo ($results['duplicate_order'] ? "✅" : "❌") . "\n";

    // Test 5: Usuario existente es detectado
    echo "5. Testing existing user... ";
    $user = User::factory()->create([
        'email' => "existing{$testId}@example.com",
        'moodle_user_id' => 999,
    ]);
    
    $result4 = $service->canProcessWebhook(
        "wh-{$testId}-3",
        "ord-{$testId}-2",
        "existing{$testId}@example.com"
    );
    $results['existing_user'] = !$result4['can_process'] && $result4['reason'] === 'user_exists';
    echo ($results['existing_user'] ? "✅" : "❌") . "\n";

    // Cleanup
    echo "\n🧹 Cleaning up... ";
    ProcessedWebhook::where('medusa_order_id', 'LIKE', "ord-{$testId}%")->delete();
    User::where('email', 'LIKE', "%{$testId}@example.com")->delete();
    echo "✅\n";

} catch (Exception $e) {
    echo "\n❌ Error: {$e->getMessage()}\n";
    echo "File: {$e->getFile()}:{$e->getLine()}\n";
    
    // Cleanup en caso de error
    ProcessedWebhook::where('medusa_order_id', 'LIKE', "ord-{$testId}%")->delete();
    User::where('email', 'LIKE', "%{$testId}@example.com")->delete();
    exit(1);
}

// Summary
echo "\n========================\n";
$allPassed = array_reduce($results, fn($carry, $result) => $carry && $result, true);

if ($allPassed) {
    echo "🎉 ALL TESTS PASSED\n";
    exit(0);
} else {
    echo "⚠️  SOME TESTS FAILED\n";
    foreach ($results as $test => $passed) {
        $status = $passed ? '✅' : '❌';
        echo "  {$status} {$test}\n";
    }
    exit(1);
}
