<?php
use App\Http\Controllers\Api\EnrollmentLogController;
use App\Http\Controllers\Api\MedusaWebhookController;

// ✅ Rutas públicas (webhooks)
Route::prefix('webhooks/medusa')
    ->middleware(['verify.webhook'])
    ->group(function () {
        Route::post('/order-paid', [MedusaWebhookController::class, 'handleOrderPaid'])
            ->name('webhooks.medusa.order-paid');
    });

// ✅ Health check público
Route::get('webhooks/medusa/health', [MedusaWebhookController::class, 'healthCheck'])
    ->name('webhooks.medusa.health');

// ✅ Rutas protegidas con Sanctum
Route::middleware(['auth:sanctum'])->group(function () {
    
    // Enrollment Logs (requiere autenticación)
    Route::prefix('enrollment-logs')->group(function () {
        Route::get('/', [EnrollmentLogController::class, 'index'])
            ->name('enrollment-logs.index');
        
        Route::get('/{id}', [EnrollmentLogController::class, 'show'])
            ->name('enrollment-logs.show');
        
        Route::get('/order/{orderId}', [EnrollmentLogController::class, 'showByOrderId'])
            ->name('enrollment-logs.by-order');
    });

    // ✅ NUEVO: Admin endpoints
    Route::prefix('admin')->group(function () {
        
        // Ver jobs fallidos
        Route::get('/failed-jobs', [\App\Http\Controllers\Api\AdminController::class, 'failedJobs'])
            ->name('admin.failed-jobs');
        
        // Reintentar job fallido
        Route::post('/failed-jobs/{id}/retry', [\App\Http\Controllers\Api\AdminController::class, 'retryJob'])
            ->name('admin.retry-job');
        
        // Ver métricas
        Route::get('/metrics', [\App\Http\Controllers\Api\AdminController::class, 'metrics'])
            ->name('admin.metrics');
    });
});

