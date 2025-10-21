<?php

namespace App\Listeners;

use App\Events\MoodleUserCreated;
use App\Jobs\EnrollUserInCourseJob;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use App\Constants\MoodleRoles;

class EnrollUserInCourseListener implements ShouldQueue
{
    public function handle(MoodleUserCreated $event): void
    {
        try {
            Log::info('🎧 Listener iniciado', [
                'user_email' => $event->user->email,
                'order_id' => $event->order->orderId,
            ]);

            if (!$event->user || !$event->user->id) {
                Log::error('❌ Usuario inválido');
                return;
            }

            $user = User::updateOrCreate(
                ['email' => $event->user->email],
                [
                    'name' => $event->user->getFullName(),
                    'moodle_user_id' => $event->user->id,
                    'medusa_order_id' => $event->order->orderId,
                    'moodle_processed_at' => now(),
                    'password' => bcrypt(str()->random(32)),
                ]
            );

            Log::info('✅ Usuario guardado', ['user_id' => $user->id]);

            $courseIds = $event->order->getCourseIds();
            if (empty($courseIds)) {
                $courseIds = [(int) config('services.moodle.default_course_id', 2)];
            }
            foreach ($courseIds as $courseId) {
                EnrollUserInCourseJob::dispatch($user, (int) $courseId, MoodleRoles::STUDENT)
                    ->delay(now()->addSeconds(10));
                
                Log::info('📤 Job despachado', [
                    'user_id' => $user->id,
                    'course_id' => $courseId,
                    'role' => MoodleRoles::getName(MoodleRoles::STUDENT),
                ]);
            }


        } catch (\Throwable $e) {
            Log::error('💥 Error en listener', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);
            throw $e;
        }
    }

    public function failed(MoodleUserCreated $event, \Throwable $exception): void
    {
        Log::critical('💥 Listener falló', [
            'error' => $exception->getMessage(),
        ]);
    }
}
