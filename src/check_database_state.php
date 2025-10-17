<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\ProcessedWebhook;
use App\Models\User;

echo "🔍 Database State Check\n";
echo "======================\n\n";

// Check users table
echo "Users table:\n";
$userCount = User::count();
echo "  Total users: {$userCount}\n";

$testUsers = User::where('email', 'LIKE', '%test%')->orWhere('email', 'LIKE', '%example.com')->count();
echo "  Test users: {$testUsers}\n";

$moodleUsers = User::whereNotNull('moodle_user_id')->count();
echo "  Users with moodle_user_id: {$moodleUsers}\n";

// Check processed_webhooks table
echo "\nProcessed Webhooks table:\n";
$webhookCount = ProcessedWebhook::count();
echo "  Total webhooks: {$webhookCount}\n";

$recentWebhooks = ProcessedWebhook::where('created_at', '>', now()->subHour())->count();
echo "  Recent webhooks (last hour): {$recentWebhooks}\n";

// Option to clean test data
echo "\n🧹 Clean all test data? (y/n): ";
$handle = fopen("php://stdin", "r");
$line = trim(fgets($handle));

if (strtolower($line) === 'y') {
    echo "Cleaning test data...\n";
    
    $deletedUsers = User::where('email', 'LIKE', '%test%')
        ->orWhere('email', 'LIKE', '%example.com')
        ->delete();
    echo "  Deleted {$deletedUsers} test users\n";
    
    $deletedWebhooks = ProcessedWebhook::where('webhook_id', 'LIKE', 'wh-%')
        ->orWhere('webhook_id', 'LIKE', 'webhook-%')
        ->delete();
    echo "  Deleted {$deletedWebhooks} test webhooks\n";
    
    echo "✅ Cleanup complete\n";
} else {
    echo "Skipped cleanup\n";
}

fclose($handle);
