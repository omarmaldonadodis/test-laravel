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
    public int $backoff = 60;

    public function __construct(
        public User $user,
        public int $courseId,
        public ?int $roleId = null
    ) {}

    public function handle(MoodleServiceInterface $moodleService): void
    {
        try {
            // Verificar que el usuario tenga moodle_user_id
            if (empty($this->user->moodle_user_id)) {
                throw new \Exception('User does not have a moodle_user_id');
            }

            Log::info('Starting course enrollment', [
                'user_id' => $this->user->id,
                'moodle_user_id' => $this->user->moodle_user_id,
                'course_id' => $this->courseId,
                'role_id' => $this->roleId
            ]);

            $result = $moodleService->enrollUserInCourse(
                (int) $this->user->moodle_user_id,
                $this->courseId,
                $this->roleId
            );

            Log::info('Course enrollment completed successfully', [
                'user_id' => $this->user->id,
                'moodle_user_id' => $this->user->moodle_user_id,
                'course_id' => $this->courseId,
                'result' => $result
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to enroll user in course', [
                'user_id' => $this->user->id,
                'moodle_user_id' => $this->user->moodle_user_id ?? null,
                'course_id' => $this->courseId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::critical('EnrollUserInCourseJob failed permanently', [
            'user_id' => $this->user->id,
            'user_email' => $this->user->email,
            'moodle_user_id' => $this->user->moodle_user_id ?? null,
            'course_id' => $this->courseId,
            'error' => $exception->getMessage()
        ]);

        // TODO: Implement notification system (email, Slack, Discord)
    }
}