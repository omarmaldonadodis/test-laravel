<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\MoodleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job: Inscribir usuario en curso Moodle
 * Principio: Maneja la lógica de inscripción sin bloquear el request
 */
class EnrollUserInCourseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected User $user;
    protected int $courseId;

    /**
     * @param User $user
     * @param int $courseId
     */
    public function __construct(User $user, int $courseId)
    {
        $this->user = $user;
        $this->courseId = $courseId;
    }

    /**
     * Ejecuta la inscripción
     */
    public function handle(MoodleService $moodleService): void
    {
        Log::info('🚀 Ejecutando EnrollUserInCourseJob', [
            'user_id' => $this->user->id,
            'course_id' => $this->courseId,
        ]);

        try {
            $moodleService->enrollUser($this->user->moodle_id, $this->courseId);

            Log::info('✅ Usuario inscrito correctamente', [
                'user_id' => $this->user->id,
                'course_id' => $this->courseId,
            ]);
        } catch (\Throwable $e) {
            Log::error('❌ Falló inscripción en Moodle', [
                'user_id' => $this->user->id,
                'course_id' => $this->courseId,
                'error' => $e->getMessage(),
            ]);

            // Reintenta automáticamente según configuración de queue
            throw $e;
        }
    }
}
