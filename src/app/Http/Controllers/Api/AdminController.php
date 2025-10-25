<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;

class AdminController extends Controller
{
    /**
     * Lista jobs fallidos
     */
    public function failedJobs(): JsonResponse
    {
        $failedJobs = DB::table('failed_jobs')
            ->orderBy('failed_at', 'desc')
            ->limit(50)
            ->get();

        return response()->json([
            'total' => $failedJobs->count(),
            'jobs' => $failedJobs->map(function ($job) {
                return [
                    'id' => $job->id,
                    'uuid' => $job->uuid,
                    'connection' => $job->connection,
                    'queue' => $job->queue,
                    'failed_at' => $job->failed_at,
                    'exception' => substr($job->exception, 0, 200) . '...',
                ];
            }),
        ]);
    }

    /**
     * Reintentar job fallido
     */
    public function retryJob(string $id): JsonResponse
    {
        try {
            Artisan::call('queue:retry', ['id' => $id]);
            
            return response()->json([
                'message' => 'Job reencolado exitosamente',
                'job_id' => $id,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'No se pudo reintentar el job',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Obtener métricas del sistema
     */
    public function metrics(): JsonResponse
    {
        $metrics = [
            'jobs' => [
                'pending' => DB::table('jobs')->count(),
                'failed' => DB::table('failed_jobs')->count(),
            ],
            'webhooks' => [
                'processed' => DB::table('processed_webhooks')->count(),
                'last_24h' => DB::table('processed_webhooks')
                    ->where('processed_at', '>', now()->subDay())
                    ->count(),
            ],
            'users' => [
                'total' => DB::table('users')->count(),
                'with_moodle_id' => DB::table('users')
                    ->whereNotNull('moodle_user_id')
                    ->count(),
            ],
            'enrollments' => [
                'failed' => DB::table('failed_enrollments')
                    ->where('requires_manual_review', true)
                    ->count(),
            ],
        ];

        return response()->json($metrics);
    }
}
