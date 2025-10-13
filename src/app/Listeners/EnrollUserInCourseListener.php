<?php

namespace App\Listeners;

use App\Events\MoodleUserCreated;
use App\Jobs\EnrollUserInCourseJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Listener: Inscribir usuario cuando se crea
 * Principio: Single Responsibility - Solo dispara jobs de inscripción
 */
class EnrollUserInCourseListener implements ShouldQueue
{
    /**
     * Maneja el evento
     */
    public function handle(MoodleUserCreated $event): void
    {
        Log::info('🎧 Listener: Procesando inscripciones', [
            'user_id' => $event->user->id,
            'order_id' => $event->order->orderId,
        ]);

        // Obtener cursos desde la orden
        $courseIds = $event->order->getCourseIds();

        // Si no hay cursos, usar el curso por defecto
        if (empty($courseIds)) {
            $defaultCourseId = config('services.moodle.default_course_id', 2);
            $courseIds = [$defaultCourseId];
            
            Log::warning('⚠️ No se encontraron cursos en items, usando curso por defecto', [
                'default_course_id' => $defaultCourseId,
            ]);
        }

        // Despachar job para cada curso
        foreach ($courseIds as $courseId) {
            EnrollUserInCourseJob::dispatch($event->user, (int) $courseId)
                ->delay(now()->addSeconds(5)); // Pequeño delay para evitar rate limiting

            Log::info('📤 Job de inscripción despachado', [
                'user_id' => $event->user->id,
                'course_id' => $courseId,
            ]);
        }
    }

    /**
     * Maneja el fallo del listener
     */
    public function failed(MoodleUserCreated $event, \Throwable $exception): void
    {
        Log::error('💥 Listener EnrollUserInCourseListener falló', [
            'user_id' => $event->user->id,
            'error' => $exception->getMessage(),
        ]);
    }
}