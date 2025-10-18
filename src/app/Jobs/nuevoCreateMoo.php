cat > app/Listeners/EnrollUserInCourseListener.php << 'EOF'
<?php

namespace App\Listeners;

use App\Contracts\MoodleServiceInterface;
use App\Events\MoodleUserCreated;
use App\Exceptions\MoodleServiceException;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Listener: Inscribir usuario en cursos después de crearlo
 * Principio: Single Responsibility - Solo maneja inscripciones
 */
class EnrollUserInCourseListener implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;
    public array $backoff = [60, 300];

    public function __construct(
        private readonly MoodleServiceInterface $moodleService
    ) {}

    public function handle(MoodleUserCreated $event): void
    {
        Log::info('📚 Iniciando inscripción en cursos', [
            'moodle_user_id' => $event->moodleUser->id,
            'order_id' => $event->order->orderId,
            'items_count' => count($event->order->items),
        ]);

        try {
            // 1. Actualizar/crear usuario local con moodle_user_id
            $localUser = $this->syncLocalUser($event);

            // 2. Inscribir en cursos basados en items
            $this->enrollInCourses($event, $localUser);

            Log::info('✅ Inscripciones completadas', [
                'moodle_user_id' => $event->moodleUser->id,
                'order_id' => $event->order->orderId,
            ]);

        } catch (MoodleServiceException $e) {
            Log::error('❌ Error en inscripción de cursos', [
                'moodle_user_id' => $event->moodleUser->id,
                'order_id' => $event->order->orderId,
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

    /**
     * Sincronizar usuario local con datos de Moodle
     */
    private function syncLocalUser(MoodleUserCreated $event): User
    {
        $user = User::firstOrCreate(
            ['email' => $event->order->customerEmail],
            [
                'name' => trim("{$event->order->customerFirstName} {$event->order->customerLastName}"),
                'password' => bcrypt(\Illuminate\Support\Str::random(32)),
            ]
        );

        // Actualizar con datos de Moodle y orden
        $user->update([
            'moodle_user_id' => $event->moodleUser->id,
            'medusa_order_id' => $event->order->orderId,
            'moodle_processed_at' => now(),
        ]);

        Log::info('✅ Usuario local sincronizado', [
            'user_id' => $user->id,
            'moodle_user_id' => $user->moodle_user_id,
            'order_id' => $event->order->orderId,
        ]);

        return $user;
    }

    /**
     * Inscribir en todos los cursos de la orden
     */
    private function enrollInCourses(MoodleUserCreated $event, User $localUser): void
    {
        $defaultCourseId = config('services.moodle.default_course_id');
        $enrolledCourses = [];

        foreach ($event->order->items as $item) {
            try {
                $courseId = $this->extractCourseId($item, $defaultCourseId);

                if (!$courseId) {
                    Log::warning('⚠️ Item sin course_id, saltando', [
                        'item_id' => $item['id'] ?? 'unknown',
                        'title' => $item['title'] ?? 'unknown',
                    ]);
                    continue;
                }

                // Evitar inscripciones duplicadas en el mismo curso
                if (in_array($courseId, $enrolledCourses)) {
                    Log::info('ℹ️ Usuario ya inscrito en este curso', [
                        'course_id' => $courseId,
                    ]);
                    continue;
                }

                Log::info('📝 Inscribiendo en curso', [
                    'moodle_user_id' => $event->moodleUser->id,
                    'course_id' => $courseId,
                    'item_title' => $item['title'] ?? 'N/A',
                ]);

                $this->moodleService->enrollUserInCourse(
                    $event->moodleUser->id,
                    (int) $courseId
                );

                $enrolledCourses[] = $courseId;

                Log::info('✅ Inscrito en curso exitosamente', [
                    'course_id' => $courseId,
                    'item_title' => $item['title'] ?? 'N/A',
                ]);

            } catch (\Exception $e) {
                Log::error('❌ Error al inscribir en curso específico', [
                    'course_id' => $courseId ?? 'unknown',
                    'item' => $item,
                    'error' => $e->getMessage(),
                ]);
                // Continuar con otros cursos
            }
        }

        Log::info('📊 Resumen de inscripciones', [
            'total_items' => count($event->order->items),
            'enrolled_courses' => count($enrolledCourses),
            'courses' => $enrolledCourses,
        ]);
    }

    /**
     * Extraer course_id del item
     */
    private function extractCourseId(array $item, ?int $default): ?int
    {
        // Prioridad: metadata > campo directo > default
        $courseId = $item['metadata']['moodle_course_id'] 
                 ?? $item['moodle_course_id'] 
                 ?? $default;

        return $courseId ? (int) $courseId : null;
    }

    /**
     * Manejar fallo del listener
     */
    public function failed(MoodleUserCreated $event, \Throwable $exception): void
    {
        Log::critical('💥 EnrollUserInCourseListener falló completamente', [
            'moodle_user_id' => $event->moodleUser->id,
            'order_id' => $event->order->orderId,
            'error' => $exception->getMessage(),
        ]);

        // TODO: Notificar admin, crear ticket, etc.
    }
}
EOF

echo "✅ Listener actualizado"