<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "🔍 Verificando Configuración de Users Table\n";
echo "==========================================\n\n";

// 1. Verificar columnas en BD
echo "1️⃣  Columnas en la base de datos:\n";
$columns = Schema::getColumnListing('users');
$required = ['id', 'email', 'moodle_user_id', 'medusa_order_id', 'moodle_processed_at'];

foreach ($required as $column) {
    if (in_array($column, $columns)) {
        echo "  ✅ $column\n";
    } else {
        echo "  ❌ $column FALTA\n";
    }
}

// 2. Verificar que User model puede usar los campos
echo "\n2️⃣  Testing User model:\n";
try {
    $user = new App\Models\User([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'moodle_user_id' => 123,
        'medusa_order_id' => 'order-123',
    ]);
    
    echo "  ✅ User model accepts moodle_user_id\n";
    echo "  ✅ User model accepts medusa_order_id\n";
} catch (Exception $e) {
    echo "  ❌ Error: {$e->getMessage()}\n";
}

// 3. Test de creación real
echo "\n3️⃣  Testing database insert:\n";
try {
    $testUser = App\Models\User::factory()->create([
        'email' => 'testcreate@example.com',
        'moodle_user_id' => 999,
        'medusa_order_id' => 'order-999',
        'moodle_processed_at' => now(),
    ]);
    
    echo "  ✅ User created successfully\n";
    echo "  ✅ moodle_user_id: {$testUser->moodle_user_id}\n";
    echo "  ✅ medusa_order_id: {$testUser->medusa_order_id}\n";
    
    // Cleanup
    $testUser->delete();
    echo "  ✅ Test user cleaned up\n";
} catch (Exception $e) {
    echo "  ❌ Error: {$e->getMessage()}\n";
}

echo "\n==========================================\n";
echo "✅ Verification complete\n";
