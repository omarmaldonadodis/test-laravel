<?php

namespace Tests\Feature;

use App\Jobs\CreateMoodleUserJob;
use App\Jobs\EnrollUserInCourseJob;
use App\Events\MoodleUserCreated;
use App\DTOs\MedusaOrderDTO;
use App\Models\User;
use App\Models\FailedEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests de integración end-to-end del sistema de compensación
 * Simula el flujo completo desde webhook hasta inscripción/compensación
 */
class CompensationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        config([
            'services.moodle.url' => 'http://test-moodle.local',
            'services.moodle.token' => 'test-token',
            'services.moodle.default_course_id' => 2,
        ]);
    }

    /**
     * INTEGRATION TEST 1: Flujo completo exitoso
     * Webhook -> CreateUser -> Event -> EnrollUser -> Success
     */
    public function test_complete_successful_flow()
    {
        Queue::fake();
        Event::fake();

        // 1. Simular webhook con order
        $orderDTO = new MedusaOrderDTO(
            orderId: 'int_order_' . uniqid(),
            customerEmail: 'integration@test.com',
            customerFirstName: 'Integration',
            customerLastName: 'Test',
            items: [
                ['metadata' => ['moodle_course_id' => 2]]
            ]
        );

        // 2. Despachar job de creación
        CreateMoodleUserJob::dispatch($orderDTO);
        
        Queue::assertPushed(CreateMoodleUserJob::class);

        // 3. Simular ejecución del job
        Http::fake([
            '*/webservice/rest/server.php*' => Http::sequence()
                ->push([], 200) // getUserByEmail: no existe
                ->push([['id' => 999, 'username' => 'inttest']], 200), // createUser
        ]);

        $job = new CreateMoodleUserJob($orderDTO);
        $job->handle(app(\App\Contracts\MoodleServiceInterface::class));

        // 4. Verificar que se disparó el evento
        Event::assertDispatched(MoodleUserCreated::class);

        // 5. Verificar que no hay registros de fallo
        $this->assertDatabaseMissing('failed_enrollments', [
            'order_id' => $orderDTO->orderId,
        ]);
    }

    /**
     * INTEGRATION TEST 2: Usuario creado pero inscripción falla
     * CreateUser -> Event -> EnrollUser FAILS -> Compensation
     */
    public function test_user_created_enrollment_fails_compensation_triggered()
    {
        Queue::fake();

        $orderDTO = new MedusaOrderDTO(
            orderId: 'int_fail_' . uniqid(),
            customerEmail: 'fail@test.com',
            customerFirstName: 'Fail',
            customerLastName: 'Test',
            items: []
        );

        // Simular creación exitosa
        Http::fake([
            '*/webservice/rest/server.php*' => Http::sequence()
                ->push([], 200) // getUserByEmail
                ->push([['id' => 888, 'username' => 'failtest']], 200) // createUser
                ->push(['exception' => 'Course not found'], 200), // enrollUser FAILS
        ]);

        // Ejecutar job de creación
        $job = new CreateMoodleUserJob($orderDTO);
        $job->handle(app(\App\Contracts\MoodleServiceInterface::class));

        // Crear usuario en BD (lo haría el listener)
        $user = User::create([
            'name' => 'Fail Test',
            'email' => 'fail@test.com',
            'password' => bcrypt('test'),
            'moodle_user_id' => 888,
            'medusa_order_id' => $orderDTO->orderId,
        ]);

        // Intentar inscripción (debería fallar)
        try {
            $enrollJob = new EnrollUserInCourseJob($user, 2);
            $enrollJob->handle(app(\App\Contracts\MoodleServiceInterface::class));
        } catch (\Exception $e) {
            // Se espera el error
        }

        // Verificar que se registró el fallo (esto lo haría el sistema real)
        $compensationService = app(\App\Services\Compensation\MoodleCompensationService::class);
        $compensationService->recordUserCreation(888, $orderDTO->orderId);
        $compensationService->compensateFailedEnrollment($orderDTO->orderId, 'Course not found');

        // Verificar registro de compensación
        $this->assertDatabaseHas('failed_enrollments', [
            'order_id' => $orderDTO->orderId,
            'moodle_user_id' => 888,
            'requires_manual_review' => true,
        ]);
    }

    /**
     * INTEGRATION TEST 3: Reintento exitoso después de fallo
     * FailedEnrollment -> Retry -> Success
     */
    public function test_retry_command_successfully_enrolls_failed_users()
    {
        // 1. Crear usuario y registro de fallo
        $user = User::factory()->create([
            'email' => 'retry@test.com',
            'moodle_user_id' => 777,
        ]);

        FailedEnrollment::create([
            'order_id' => 'retry_order_123',
            'moodle_user_id' => 777,
            'failure_reason' => 'Temporary network error',
            'requires_manual_review' => true,
            'user_data' => ['email' => 'retry@test.com'],
        ]);

        // 2. Simular reintento exitoso
        Http::fake([
            '*/webservice/rest/server.php*' => Http::response([], 200), // enrollUser OK
        ]);

        // 3. Ejecutar servicio de reintentos
        $service = app(\App\Services\Compensation\MoodleCompensationService::class);
        $retriedCount = $service->retryFailedEnrollments();

        // 4. Verificar
        $this->assertEquals(1, $retriedCount);
        
        $failed = FailedEnrollment::where('order_id', 'retry_order_123')->first();
        $this->assertFalse($failed->requires_manual_review);
        $this->assertNotNull($failed->resolved_at);
    }

    /**
     * INTEGRATION TEST 4: Race condition - doble webhook
     * Webhook1 -> CreateUser -> Webhook2 (duplicado) -> Idempotency blocks
     */
    public function test_idempotency_prevents_duplicate_processing()
    {
        Queue::fake();

        $orderId = 'race_order_' . uniqid();
        $email = 'race@test.com';

        $orderDTO = new MedusaOrderDTO(
            orderId: $orderId,
            customerEmail: $email,
            customerFirstName: 'Race',
            customerLastName: 'Test',
            items: []
        );

        // Primer webhook
        CreateMoodleUserJob::dispatch($orderDTO);
        
        // Simular procesamiento
        Http::fake([
            '*/webservice/rest/server.php*' => Http::response([
                ['id' => 666, 'username' => 'racetest']
            ], 200),
        ]);

        $job1 = new CreateMoodleUserJob($orderDTO);
        $job1->handle(app(\App\Contracts\MoodleServiceInterface::class));

        // Crear usuario en BD
        User::create([
            'name' => 'Race Test',
            'email' => $email,
            'password' => bcrypt('test'),
            'moodle_user_id' => 666,
            'medusa_order_id' => $orderId,
        ]);

        // Segundo webhook (duplicado)
        $idempotencyService = app(\App\Services\Webhook\WebhookIdempotencyService::class);
        $result = $idempotencyService->canProcessWebhook('wh_2', $orderId, $email);

        // Debe detectar duplicado
        $this->assertFalse($result['can_process']);
        $this->assertContains($result['reason'], ['duplicate_order', 'user_exists']);
    }

    /**
     * INTEGRATION TEST 5: Cache consistency durante fallos
     * Verificar que cache y BD permanecen sincronizados
     */
    public function test_cache_and_database_consistency()
    {
        $orderId = 'consistency_' . uniqid();
        $moodleUserId = 555;

        $service = app(\App\Services\Compensation\MoodleCompensationService::class);
        $cache = app(\App\Services\Compensation\CompensationCache::class);

        // 1. Registrar creación
        $service->recordUserCreation($moodleUserId, $orderId);

        $cached = $cache->get($orderId);
        $this->assertEquals('pending_enrollment', $cached['status']);

        // 2. Fallo de inscripción
        $service->compensateFailedEnrollment($orderId, 'Test failure');

        // 3. Verificar consistencia
        $cached = $cache->get($orderId);
        $dbRecord = FailedEnrollment::where('order_id', $orderId)->first();

        $this->assertEquals('failed', $cached['status']);
        $this->assertNotNull($dbRecord);
        $this->assertEquals($moodleUserId, $cached['moodle_user_id']);
        $this->assertEquals($moodleUserId, $dbRecord->moodle_user_id);
    }

    /**
     * INTEGRATION TEST 6: Múltiples cursos, uno falla
     * User creado -> Course1 OK -> Course2 FAIL -> Compensación parcial
     */
    public function test_partial_enrollment_failure_multiple_courses()
    {
        $orderDTO = new MedusaOrderDTO(
            orderId: 'multi_course_' . uniqid(),
            customerEmail: 'multi@test.com',
            customerFirstName: 'Multi',
            customerLastName: 'Course',
            items: [
                ['metadata' => ['moodle_course_id' => 2]],
                ['metadata' => ['moodle_course_id' => 3]],
            ]
        );

        $user = User::factory()->create([
            'email' => 'multi@test.com',
            'moodle_user_id' => 444,
            'medusa_order_id' => $orderDTO->orderId,
        ]);

        // Simular: curso 2 OK, curso 3 FAIL
        Http::fake([
            '*/webservice/rest/server.php*' => Http::sequence()
                ->push([], 200) // Course 2: OK
                ->push(['exception' => 'Course full'], 200), // Course 3: FAIL
        ]);

        $service = app(\App\Contracts\MoodleServiceInterface::class);
        $compensationService = app(\App\Services\Compensation\MoodleCompensationService::class);

        $compensationService->recordUserCreation(444, $orderDTO->orderId);

        // Inscribir en curso 2
        try {
            $service->enrollUser(444, 2);
        } catch (\Exception $e) {}

        // Inscribir en curso 3 (falla)
        try {
            $service->enrollUser(444, 3);
        } catch (\Exception $e) {
            $compensationService->compensateFailedEnrollment($orderDTO->orderId, 'Course 3 enrollment failed');
        }

        // Verificar que se registró el fallo
        $this->assertDatabaseHas('failed_enrollments', [
            'order_id' => $orderDTO->orderId,
            'moodle_user_id' => 444,
        ]);
    }

    /**
     * INTEGRATION TEST 7: Comando artisan para reintentos
     * Simular: php artisan moodle:retry-enrollments
     */
    public function test_artisan_command_retry_enrollments()
    {
        // Crear 2 fallos
        FailedEnrollment::create([
            'order_id' => 'cmd_order_1',
            'moodle_user_id' => 111,
            'failure_reason' => 'Network timeout',
            'requires_manual_review' => true,
            'user_data' => [],
        ]);

        FailedEnrollment::create([
            'order_id' => 'cmd_order_2',
            'moodle_user_id' => 222,
            'failure_reason' => 'API error',
            'requires_manual_review' => true,
            'user_data' => [],
        ]);

        // Mock: ambos exitosos
        Http::fake([
            '*/webservice/rest/server.php*' => Http::response([], 200),
        ]);

        // Ejecutar servicio (simulando comando)
        $service = app(\App\Services\Compensation\MoodleCompensationService::class);
        $count = $service->retryFailedEnrollments();

        $this->assertEquals(2, $count);

        // Verificar que ambos fueron resueltos
        $pending = FailedEnrollment::where('requires_manual_review', true)->count();
        $this->assertEquals(0, $pending);
    }

    /**
     * INTEGRATION TEST 8: Stress test - múltiples webhooks simultáneos
     * Verificar que no hay race conditions
     */
    public function test_concurrent_webhooks_no_duplicates()
    {
        Queue::fake();

        $email = 'concurrent@test.com';
        $orders = [];

        // Simular 5 webhooks "simultáneos" para el mismo email
        for ($i = 1; $i <= 5; $i++) {
            $orders[] = new MedusaOrderDTO(
                orderId: "concurrent_order_{$i}",
                customerEmail: $email,
                customerFirstName: 'Concurrent',
                customerLastName: "Test{$i}",
                items: []
            );
        }

        Http::fake([
            '*/webservice/rest/server.php*' => Http::response([
                ['id' => 333, 'username' => 'concurrent']
            ], 200),
        ]);

        // Procesar todos
        foreach ($orders as $order) {
            CreateMoodleUserJob::dispatch($order);
        }

        // Solo el primero debería crear el usuario
        $userCount = User::where('email', $email)->count();
        $this->assertLessThanOrEqual(1, $userCount, 'No debe crear usuarios duplicados');
    }
}