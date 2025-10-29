<?php

namespace App\Jobs;
use App\Contracts\MoodleServiceInterface;
use App\DTOs\MoodleUserDTO;
use App\Exceptions\MoodleServiceException;
use App\Constants\MoodleRoles;
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
    public array $backoff = [60, 300, 900];

    public function __construct(
        private readonly MoodleUserDTO $user,
        private readonly int $courseId,
        private readonly ?int $roleId = MoodleRoles::STUDENT
    ) {}

    public function handle(MoodleServiceInterface $moodleService): void
    {
        Log::info('🎓 Iniciando inscripción', [
            'user_id' => $this->user->id,
            'course_id' => $this->courseId,
            'attempt' => $this->attempts(),
        ]);

        try {
            if ($moodleService->isUserEnrolled($this->user->id, $this->courseId)) {
                Log::info('ℹ️ Usuario ya inscrito', ['user_id' => $this->user->id]);
                return;
            }

            $enrolled = $moodleService->enrollUser(
                $this->user->id,
                $this->courseId,
                $this->roleId
            );

            if (!$enrolled) {
                throw MoodleServiceException::enrollmentFailed(
                    $this->user->id,
                    $this->courseId,
                    'Service returned false'
                );
            }

            Log::info('✅ Inscripción exitosa', [
                'user_id' => $this->user->id,
                'course_id' => $this->courseId,
            ]);

        } catch (\Throwable $e) {
            Log::error('❌ Error en inscripción', [
                'user_id' => $this->user->id,
                'course_id' => $this->courseId,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
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
        Log::critical('💥 EnrollUserInCourseJob falló permanentemente', [
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
