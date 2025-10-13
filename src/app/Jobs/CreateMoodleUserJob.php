<?php

namespace App\Jobs;

use App\Contracts\MoodleServiceInterface;
use App\DTOs\MedusaOrderDTO;
use App\DTOs\MoodleUserDTO;
use App\Events\MoodleUserCreated;
use App\Exceptions\MoodleServiceException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job: Crear usuario en Moodle
 * Principio: Single Responsibility - Solo crea usuarios
 */
class CreateMoodleUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Número de intentos
     */
    public int $tries = 3;

    /**
     * Tiempo de espera entre reintentos (segundos)
     */
    public array $backoff = [60, 300, 900]; // 1min, 5min, 15min

    /**
     * Timeout del job (segundos)
     */
    public int $timeout = 120;

    /**
     * Constructor
     */
    public function __construct(
        private readonly MedusaOrderDTO $order
    ) {}

    /**
     * Ejecuta el job
     */
    public function handle(MoodleServiceInterface $moodleService): void
    {
        Log::info('🚀 Iniciando creación de usuario Moodle', [
            'order_id' => $this->order->orderId,
            'email' => $this->order->customerEmail,
            'attempt' => $this->attempts(),
        ]);

        try {
            // Validar datos
            if (!$this->order->isValid()) {
                throw new MoodleServiceException(
                    'Invalid order data',
                    422,
                    null,
                    $this->order->toArray()
                );
            }

            // Generar credenciales
            $username = $moodleService->generateUsername($this->order->customerEmail);
            $password = $moodleService->generatePassword();

            // Crear DTO de usuario
            $userDTO = MoodleUserDTO::fromMedusaOrder($this->order, $username, $password);

            // Crear usuario en Moodle
            $moodleUserData = $moodleService->createUser($userDTO->toMoodleCreateFormat());

            if (!$moodleUserData) {
                throw MoodleServiceException::userCreationFailed(
                    $this->order->customerEmail,
                    'Service returned null',
                    ['order_id' => $this->order->orderId]
                );
            }

            $moodleUser = MoodleUserDTO::fromMoodleResponse($moodleUserData);

            Log::info('✅ Usuario Moodle creado/encontrado', [
                'order_id' => $this->order->orderId,
                'moodle_user_id' => $moodleUser->id,
                'existing' => $moodleUser->existing,
            ]);

            // Disparar evento para inscripción
            event(new MoodleUserCreated($moodleUser, $this->order));

        } catch (MoodleServiceException $e) {
            Log::error('❌ Error en CreateMoodleUserJob', [
                'order_id' => $this->order->orderId,
                'error' => $e->getMessage(),
                'context' => $e->getContext(),
                'attempt' => $this->attempts(),
            ]);

            // Si es el último intento, no reintentar
            if ($this->attempts() >= $this->tries) {
                $this->fail($e);
                return;
            }

            throw $e; // Reintentar
        }
    }

    /**
     * Maneja el fallo del job
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('💥 Job CreateMoodleUserJob falló completamente', [
            'order_id' => $this->order->orderId,
            'email' => $this->order->customerEmail,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        // Aquí podrías enviar notificación al admin, guardar en BD, etc.
    }

    /**
     * Tags para Horizon
     */
    public function tags(): array
    {
        return [
            'moodle',
            'user-creation',
            "order:{$this->order->orderId}",
            "email:{$this->order->customerEmail}",
        ];
    }
}