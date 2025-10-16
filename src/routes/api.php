<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\MedusaWebhookController;
use App\Http\Controllers\Api\EnrollmentLogController;

/*
|--------------------------------------------------------------------------
| API Routes - Fashion Starter Integration
|--------------------------------------------------------------------------
*/

// Webhooks de Medusa con validación HMAC
Route::prefix('webhooks/medusa')
    ->middleware(['verify.webhook'])
    ->group(function () {
        Route::post('/order-paid', [MedusaWebhookController::class, 'handleOrderPaid'])
            ->name('webhooks.medusa.order-paid');

    });


// Endpoints de EnrollmentLogs (protegidos)
Route::middleware(['auth:sanctum'])->prefix('enrollment-logs')->group(function () {
    Route::get('/stats', [EnrollmentLogController::class, 'stats'])->name('enrollment-logs.stats'); // ← NUEVO
    Route::get('/', [EnrollmentLogController::class, 'index'])->name('enrollment-logs.index');
    Route::get('/{id}', [EnrollmentLogController::class, 'show'])->name('enrollment-logs.show');
    Route::get('/order/{orderId}', [EnrollmentLogController::class, 'showByOrderId'])->name('enrollment-logs.by-order');
});



