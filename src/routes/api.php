<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\MedusaWebhookController;
use App\Http\Middleware\CorsMiddleware; // Asegúrate de importar tu middleware

/*
|--------------------------------------------------------------------------
| API Routes - Fashion Starter Integration
|--------------------------------------------------------------------------
*/

// Webhooks de Medusa con validación HMAC
Route::prefix('webhooks/medusa')
    ->middleware(['verify.webhook', CorsMiddleware::class])
    ->group(function () {
        Route::post('/order-paid', [MedusaWebhookController::class, 'handleOrderPaid'])
            ->name('webhooks.medusa.order-paid');

    });


// Ruta de prueba para verificar conectividad
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'Laravel Webhook Gateway',
        'timestamp' => now()->toIso8601String(),
    ]);
})->name('health');

// Ruta de prueba para Moodle
Route::get('/moodle/test', function () {
    $moodleService = app(\App\Services\MoodleService::class);
    $connected = $moodleService->testConnection();
    
    return response()->json([
        'moodle_connected' => $connected,
        'moodle_url' => env('MOODLE_URL'),
    ]);
})->name('moodle.test');
