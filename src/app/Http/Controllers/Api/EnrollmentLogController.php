<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EnrollmentLogResource;
use App\Http\Resources\EnrollmentLogCollection;
use App\Models\EnrollmentLog;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Controller para gestionar logs de inscripciones
 * 
 * Endpoints protegidos con Sanctum
 */
class EnrollmentLogController extends Controller
{
    /**
     * Lista paginada de enrollment logs
     * 
     * @param Request $request
     * @return EnrollmentLogCollection
     * 
     * Query params:
     * - status: filtrar por status (completed, failed, pending)
     * - email: buscar por email del cliente
     * - per_page: número de resultados por página (default: 15)
     */
    public function index(Request $request): EnrollmentLogCollection
    {
        $logs = EnrollmentLog::query()
            // Filtrar por status si viene en la request
            ->when($request->status, function ($query, $status) {
                return $query->where('status', $status);
            })
            // Filtrar por email si viene en la request
            ->when($request->email, function ($query, $email) {
                return $query->where('customer_email', 'like', "%{$email}%");
            })
            // Ordenar por más recientes primero
            ->latest()
            // Paginar resultados
            ->paginate($request->per_page ?? 15);

        return new EnrollmentLogCollection($logs);
    }

    /**
     * Muestra un enrollment log específico por ID
     * 
     * @param string $id
     * @return EnrollmentLogResource|JsonResponse
     */
    public function show(string $id): EnrollmentLogResource|JsonResponse
    {
        $log = EnrollmentLog::find($id);

        if (!$log) {
            return response()->json([
                'error' => [
                    'message' => 'Enrollment log not found',
                    'code' => 'NOT_FOUND',
                ],
            ], 404);
        }

        return new EnrollmentLogResource($log);
    }

    /**
     * Busca enrollment logs por order_id de Medusa
     * 
     * @param string $orderId
     * @return EnrollmentLogResource|JsonResponse
     */
    public function showByOrderId(string $orderId): EnrollmentLogResource|JsonResponse
    {
        $log = EnrollmentLog::where('medusa_order_id', $orderId)->first();

        if (!$log) {
            return response()->json([
                'error' => [
                    'message' => "Enrollment log not found for order: {$orderId}",
                    'code' => 'NOT_FOUND',
                ],
            ], 404);
        }

        return new EnrollmentLogResource($log);
    }

    /**
     * Estadísticas de inscripciones
     * 
     * @return JsonResponse
     */
    public function stats(): JsonResponse
    {
        $stats = [
            'total' => EnrollmentLog::count(),
            'completed' => EnrollmentLog::where('status', 'completed')->count(),
            'failed' => EnrollmentLog::where('status', 'failed')->count(),
            'pending' => EnrollmentLog::where('status', 'pending')->count(),
            'last_24h' => EnrollmentLog::where('created_at', '>=', now()->subDay())->count(),
            'last_7d' => EnrollmentLog::where('created_at', '>=', now()->subDays(7))->count(),
        ];

        return response()->json([
            'data' => $stats,
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}