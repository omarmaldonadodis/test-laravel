<?php

namespace App\Listeners;

use App\Events\MoodleUserCreated;
use App\Jobs\EnrollUserInCourseJob;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;


class EnrollUserInCourseListener implements ShouldQueue
{
    public function handle(MoodleUserCreated $event): void
    {
        // Logging completo del evento
        Log::info('🎧 Listener: Payload completo recibido', [
            'user' => $event->user ? $event->user->toArray() : null,
            'order' => $event->order ? $event->order->toArray() : null,
        ]);

        // Convertir DTO a modelo User
        $userDto = $event->user;
        if (!$userDto || empty($userDto->email)) {
            Log::error('❌ Usuario inválido, no se puede despachar job de inscripción', [
                'user' => $userDto ? $userDto->toArray() : null,
            ]);
            return;
        }

        $user = User::updateOrCreate(
            ['email' => $userDto->email],
            [
                'name' => $userDto->fullName ?? ($userDto->firstName . ' ' . $userDto->lastName),
                'moodle_user_id' => $userDto->id,
                'medusa_order_id' => $event->order->orderId ?? null,
            ]
        );

        // Obtener cursos desde la orden
        $courseIds = [];
        if (method_exists($event->order, 'getCourseIds')) {
            $courseIds = $event->order->getCourseIds() ?? [];
        }

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
            EnrollUserInCourseJob::dispatch($user, (int) $courseId)
                ->delay(now()->addSeconds(5));

            Log::info('📤 Job de inscripción despachado', [
                'user_id' => $user->id,
                'course_id' => $courseId,
            ]);
        }
    }

    public function failed(MoodleUserCreated $event, \Throwable $exception): void
    {
        Log::error('💥 Listener EnrollUserInCourseListener falló', [
            'user_id' => $event->user->id ?? null,
            'error' => $exception->getMessage(),
        ]);
    }
}
