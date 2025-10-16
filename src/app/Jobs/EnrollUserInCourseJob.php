<?php

namespace App\Jobs;

use App\Contracts\MoodleServiceInterface;
use App\DTOs\MoodleUserDTO;
use App\Exceptions\MoodleServiceException;
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
    public array $backoff = [60, 300, 900];
    public int $timeout = 120;

    public function __construct(
        private readonly MoodleUserDTO $user,
        private readonly int $courseId
    ) {}

    public function handle(MoodleServiceInterface $moodleService): void
    {
        Log::info('🎓 Iniciando inscripción', [
            'user_id' => $this->user->id,
            'course_id' => $this->courseId,
            'attempt' => $this->attempts(),
        ]);

        try {
            // Verificar si ya está inscrito
            if ($moodleService->isUserEnrolled($this->user->id, $this->courseId)) {
                Log::info('✅ Usuario ya inscrito');
                return;
            }

            // Inscribir
            $enrolled = $moodleService->enrollUser($this->user->id, $this->courseId);

            if (!$enrolled) {
                throw MoodleServiceException::enrollmentFailed(
                    $this->user->id,
                    $this->courseId,
                    'Service returned false'
                );
            }

            Log::info('✅ Inscripción exitosa');

        } catch (MoodleServiceException $e) {
            Log::error('❌ Error en inscripción', [
                'error' => $e->getMessage(),
                'context' => $e->getContext(),
            ]);

            if ($this->attempts() >= $this->tries) {
                $this->fail($e);
                return;
            }

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('💥 Job de inscripción falló', [
            'user_id' => $this->user->id,
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