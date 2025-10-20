<?php

namespace App\Jobs;

use App\Contracts\MoodleServiceInterface;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class EnrollUserInCourseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    public array $backoff = [30, 60, 120];

    public function __construct(
        public User $user,
        public int $courseId,
        public ?int $roleId = null
    ) {}

    public function handle(MoodleServiceInterface $moodleService): void
    {
        try {
            // Refrescar el usuario desde BD para obtener datos actualizados
            $this->user->refresh();

            Log::info('📚 Iniciando inscripción', [
                'user_id' => $this->user->id,
                'moodle_user_id' => $this->user->moodle_user_id,
                'course_id' => $this->courseId,
            ]);

            // Validar que el usuario tenga moodle_user_id
            if (empty($this->user->moodle_user_id)) {
                throw new \Exception(
                    "Usuario {$this->user->id} no tiene moodle_user_id. " .
                    "Posiblemente el CreateMoodleUserJob aún no terminó."
                );
            }

            // Verificar si ya está inscrito
            if ($moodleService->isUserEnrolled((int) $this->user->moodle_user_id, $this->courseId)) {
                Log::info('ℹ️ Usuario ya inscrito', [
                    'user_id' => $this->user->id,
                    'moodle_user_id' => $this->user->moodle_user_id,
                    'course_id' => $this->courseId,
                ]);
                return;
            }

            // Inscribir en Moodle
            $result = $moodleService->enrollUser(
                (int) $this->user->moodle_user_id,
                $this->courseId,
                $this->roleId ?? 5
            );

            Log::info('✅ Inscripción exitosa', [
                'user_id' => $this->user->id,
                'moodle_user_id' => $this->user->moodle_user_id,
                'course_id' => $this->courseId,
                'result' => $result,
            ]);

        } catch (\Throwable $e) {
            Log::error('❌ Error en inscripción', [
                'user_id' => $this->user->id,
                'user_email' => $this->user->email,
                'moodle_user_id' => $this->user->moodle_user_id ?? null,
                'course_id' => $this->courseId,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            // Si es el último intento, no reintentar
            if ($this->attempts() >= $this->tries) {
                $this->fail($e);
                return;
            }

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::critical('💥 EnrollUserInCourseJob falló permanentemente', [
            'user_id' => $this->user->id,
            'user_email' => $this->user->email,
            'moodle_user_id' => $this->user->moodle_user_id ?? null,
            'course_id' => $this->courseId,
            'error' => $exception->getMessage(),
        ]);
    }

    public function tags(): array
    {
        return [
            'enrollment',
            "user:{$this->user->id}",
            "course:{$this->courseId}",
        ];
    }
}
