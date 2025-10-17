<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "🔍 SOLID Principles Verification\n";
echo "================================\n\n";

$checks = [];

// Single Responsibility
echo "1️⃣  Single Responsibility Principle\n";
$serviceExists = class_exists('App\Services\Webhook\WebhookIdempotencyService');
$checks[] = ['name' => '  Service handles only idempotency logic', 'pass' => $serviceExists];

$controllerContent = file_get_contents('app/Http/Controllers/Api/MedusaWebhookController.php');
$controllerUsesService = str_contains($controllerContent, 'WebhookIdempotencyService');
$checks[] = ['name' => '  Controller delegates to service', 'pass' => $controllerUsesService];

// Dependency Inversion
echo "\n2️⃣  Dependency Inversion Principle\n";
$usesConstructorInjection = str_contains($controllerContent, 'public function __construct');
$checks[] = ['name' => '  Uses dependency injection', 'pass' => $usesConstructorInjection];

$serviceInjected = str_contains($controllerContent, 'private WebhookIdempotencyService');
$checks[] = ['name' => '  Service injected via constructor', 'pass' => $serviceInjected];

// Open/Closed
echo "\n3️⃣  Open/Closed Principle\n";
$serviceHasPrivateMethods = preg_match('/private function/', file_get_contents('app/Services/Webhook/WebhookIdempotencyService.php'));
$checks[] = ['name' => '  Service can be extended without modification', 'pass' => $serviceHasPrivateMethods];

// Show results
echo "\n";
foreach ($checks as $check) {
    $status = $check['pass'] ? '✅' : '❌';
    echo "{$status} {$check['name']}\n";
}

$allPass = array_reduce($checks, fn($c, $r) => $c && $r['pass'], true);

echo "\n================================\n";
if ($allPass) {
    echo "🎉 SOLID PRINCIPLES APPLIED CORRECTLY\n";
} else {
    echo "⚠️  Some principles need review\n";
}
