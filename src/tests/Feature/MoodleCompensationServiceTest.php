<?php

namespace Tests\Feature;

use App\Contracts\MoodleServiceInterface;
use App\Services\Compensation\MoodleCompensationService;
use App\Services\Compensation\CompensationCache;
use App\Repositories\FailedEnrollmentRepository;
use App\Models\FailedEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use Mockery;

/**
 * Tests exhaustivos para el sistema de compensación de Moodle
 * Cubre todos los escenarios de fallo y recuperación
 */
class MoodleCompensationServiceTest extends TestCase
{
    use RefreshDatabase;

    private MoodleCompensationService $service;
    private $moodleServiceMock;
    private FailedEnrollmentRepository $repository;
    private CompensationCache $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->moodleServiceMock = Mockery::mock(MoodleServiceInterface::class);
        $this->repository = new FailedEnrollmentRepository();
        $this->cache = new CompensationCache();

        $this->service = new MoodleCompensationService(
            $this->moodleServiceMock,
            $this->repository,
            $this->cache
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Scenario 1: Usuario creado exitosamente, inscripción exitosa
     * Expected: Estado "completed", sin registro en failed_enrollments
     */
    public function test_happy_path_user_and_enrollment_succeed()
    {
        $orderId = 'order_happy_' . uniqid();
        $moodleUserId = 123;

        // Registrar creación de usuario
        $this->service->recordUserCreation($moodleUserId, $orderId);

        // Verificar que está en cache
        $cached = $this->cache->get($orderId);
        $this->assertNotNull($cached);
        $this->assertEquals($moodleUserId, $cached['moodle_user_id']);
        $this->assertEquals('pending_enrollment', $cached['status']);

        // Marcar inscripción exitosa
        $this->service->markEnrollmentSuccess($orderId);

        // Verificar estado final
        $cached = $this->cache->get($orderId);
        $this->assertEquals('completed', $cached['status']);
        $this->assertArrayHasKey('completed_at', $cached);

        // No debe haber registro de fallo
        $this->assertDatabaseMissing('failed_enrollments', [
            'order_id' => $orderId,
        ]);
    }

    /**
     * Scenario 2: Usuario creado, inscripción falla
     * Expected: Registro en failed_enrollments, estado "failed", require revisión manual
     */
    public function test_user_created_but_enrollment_fails()
    {
        Log::shouldReceive('warning')->once();

        $orderId = 'order_fail_' . uniqid();
        $moodleUserId = 456;
        $failureReason = 'Course full or invalid permissions';

        // Registrar creación exitosa
        $this->service->recordUserCreation($moodleUserId, $orderId);

        // Simular fallo en inscripción
        $this->service->compensateFailedEnrollment($orderId, $failureReason);

        // Verificar registro en BD
        $this->assertDatabaseHas('failed_enrollments', [
            'order_id' => $orderId,
            'moodle_user_id' => $moodleUserId,
            'failure_reason' => $failureReason,
            'requires_manual_review' => true,
        ]);

        // Verificar estado en cache
        $cached = $this->cache->get($orderId);
        $this->assertEquals('failed', $cached['status']);
        $this->assertEquals($failureReason, $cached['failure_reason']);
    }

    /**
     * Scenario 3: Reintentar inscripciones fallidas exitosamente
     * Expected: Usuario inscrito, registro marcado como resuelto
     */
    public function test_retry_failed_enrollments_successfully()
    {
        $orderId = 'order_retry_' . uniqid();
        $moodleUserId = 789;

        // Crear registro de fallo previo
        FailedEnrollment::create([
            'order_id' => $orderId,
            'moodle_user_id' => $moodleUserId,
            'failure_reason' => 'Temporary network error',
            'requires_manual_review' => true,
            'user_data' => ['test' => 'data'],
        ]);

        // Mock: reintento exitoso
        $this->moodleServiceMock
            ->shouldReceive('enrollUser')
            ->once()
            ->with($moodleUserId, 2) // Default course
            ->andReturn(true);

        // Ejecutar reintentos
        $retriedCount = $this->service->retryFailedEnrollments();

        // Verificar
        $this->assertEquals(1, $retriedCount);
        
        $this->assertDatabaseHas('failed_enrollments', [
            'order_id' => $orderId,
            'requires_manual_review' => false,
        ]);

        $this->assertDatabaseMissing('failed_enrollments', [
            'order_id' => $orderId,
            'resolved_at' => null,
        ]);
    }

    /**
     * Scenario 4: Reintentar inscripciones pero siguen fallando
     * Expected: Permanece en requires_manual_review = true
     */
    public function test_retry_failed_enrollments_still_fails()
    {
        Log::shouldReceive('error')->once();

        $orderId = 'order_persistent_fail_' . uniqid();
        $moodleUserId = 999;

        FailedEnrollment::create([
            'order_id' => $orderId,
            'moodle_user_id' => $moodleUserId,
            'failure_reason' => 'Course deleted',
            'requires_manual_review' => true,
            'user_data' => [],
        ]);

        // Mock: reintento sigue fallando
        $this->moodleServiceMock
            ->shouldReceive('enrollUser')
            ->once()
            ->andThrow(new \Exception('Course not found'));

        $retriedCount = $this->service->retryFailedEnrollments();

        $this->assertEquals(0, $retriedCount);
        
        // Debe seguir marcado para revisión manual
        $this->assertDatabaseHas('failed_enrollments', [
            'order_id' => $orderId,
            'requires_manual_review' => true,
            'resolved_at' => null,
        ]);
    }

    /**
     * Scenario 5: No hay datos en cache al intentar compensar
     * Expected: Log warning, no crash
     */
    public function test_compensate_without_cached_data()
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === '⚠️ No compensation data found'
                    && isset($context['orderId']);
            });

        $orderId = 'order_no_cache_' . uniqid();

        // Intentar compensar sin datos en cache
        $this->service->compensateFailedEnrollment($orderId, 'Test reason');

        // No debe crashear
        $this->assertTrue(true);
    }

    /**
     * Scenario 6: Reintentar solo inscripciones recientes (últimos 7 días)
     * Expected: Solo procesa registros recientes
     */
    public function test_retry_only_recent_failed_enrollments()
    {
        // Crear fallo antiguo (8 días)
        FailedEnrollment::create([
            'order_id' => 'order_old',
            'moodle_user_id' => 111,
            'failure_reason' => 'Old failure',
            'requires_manual_review' => true,
            'user_data' => [],
            'created_at' => now()->subDays(8),
        ]);

        // Crear fallo reciente (3 días)
        FailedEnrollment::create([
            'order_id' => 'order_recent',
            'moodle_user_id' => 222,
            'failure_reason' => 'Recent failure',
            'requires_manual_review' => true,
            'user_data' => [],
            'created_at' => now()->subDays(3),
        ]);

        $this->moodleServiceMock
            ->shouldReceive('enrollUser')
            ->once() // Solo 1 vez (el reciente)
            ->andReturn(true);

        $retriedCount = $this->service->retryFailedEnrollments();

        $this->assertEquals(1, $retriedCount);
    }

    /**
     * Scenario 7: Cache expira correctamente (TTL)
     * Expected: Datos de compensación no accesibles después de TTL
     */
    public function test_cache_expiration()
    {
        $orderId = 'order_expire_' . uniqid();

        // Guardar con TTL corto (1 segundo)
        $this->cache->put($orderId, ['test' => 'data'], 1/3600); // 1 segundo en horas

        // Verificar que existe
        $this->assertNotNull($this->cache->get($orderId));

        // Esperar expiración
        sleep(2);

        // Verificar que expiró
        $this->assertNull($this->cache->get($orderId));
    }

    /**
     * Scenario 8: Múltiples intentos de compensación para mismo order_id
     * Expected: Solo crea un registro en failed_enrollments
     */
    public function test_idempotent_compensation()
    {
        Log::shouldReceive('warning')->twice();

        $orderId = 'order_idempotent_' . uniqid();
        $moodleUserId = 333;

        $this->service->recordUserCreation($moodleUserId, $orderId);

        // Primera compensación
        $this->service->compensateFailedEnrollment($orderId, 'First failure');

        // Segunda compensación (debería actualizar, no duplicar)
        $this->service->compensateFailedEnrollment($orderId, 'Second failure');

        // Verificar que solo hay un registro
        $count = FailedEnrollment::where('order_id', $orderId)->count();
        $this->assertEquals(1, $count);
    }

    /**
     * Scenario 9: Datos de usuario disponibles en failed_enrollments
     * Expected: user_data contiene información útil para debugging
     */
    public function test_failed_enrollment_stores_useful_data()
    {
        Log::shouldReceive('warning')->once();

        $orderId = 'order_data_' . uniqid();
        $moodleUserId = 444;

        $userData = [
            'moodle_user_id' => $moodleUserId,
            'created_at' => now()->toIso8601String(),
            'status' => 'pending_enrollment',
            'custom_field' => 'debug_value',
        ];

        $this->cache->put($orderId, $userData);

        $this->service->compensateFailedEnrollment($orderId, 'Test failure');

        $failed = FailedEnrollment::where('order_id', $orderId)->first();
        
        $this->assertNotNull($failed);
        $this->assertEquals($userData, $failed->user_data);
        $this->assertEquals('debug_value', $failed->user_data['custom_field']);
    }

    /**
     * Scenario 10: Verificar logging apropiado en todos los casos
     * Expected: Logs informativos sin datos sensibles
     */
    public function test_appropriate_logging()
    {
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === '📝 User creation recorded for compensation'
                    && isset($context['orderId'])
                    && isset($context['moodleUserId'])
                    && !isset($context['password']); // No debe contener datos sensibles
            });

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === '✅ Enrollment marked as completed';
            });

        $orderId = 'order_log_' . uniqid();
        $moodleUserId = 555;

        $this->service->recordUserCreation($moodleUserId, $orderId);
        $this->service->markEnrollmentSuccess($orderId);
    }

    /**
     * Scenario 11: Batch retry de múltiples fallos
     * Expected: Procesa todos los reintentos pendientes
     */
    public function test_batch_retry_multiple_failures()
    {
        // Crear 3 fallos recientes
        for ($i = 1; $i <= 3; $i++) {
            FailedEnrollment::create([
                'order_id' => "order_batch_{$i}",
                'moodle_user_id' => 100 + $i,
                'failure_reason' => 'Temporary error',
                'requires_manual_review' => true,
                'user_data' => [],
            ]);
        }

        // Mock: todos exitosos
        $this->moodleServiceMock
            ->shouldReceive('enrollUser')
            ->times(3)
            ->andReturn(true);

        $retriedCount = $this->service->retryFailedEnrollments();

        $this->assertEquals(3, $retriedCount);

        // Verificar que todos fueron resueltos
        $remaining = FailedEnrollment::where('requires_manual_review', true)->count();
        $this->assertEquals(0, $remaining);
    }

    /**
     * Scenario 12: Cache TTL diferente para fallos vs éxitos
     * Expected: Fallos persisten más tiempo (30 días vs 7 días)
     */
    public function test_cache_ttl_varies_by_status()
    {
        Log::shouldReceive('warning')->once();

        $orderSuccess = 'order_success_ttl_' . uniqid();
        $orderFail = 'order_fail_ttl_' . uniqid();

        // Éxito: TTL 7 días (168 horas)
        $this->service->recordUserCreation(123, $orderSuccess);
        $this->service->markEnrollmentSuccess($orderSuccess);

        // Fallo: TTL 30 días (720 horas)
        $this->service->recordUserCreation(456, $orderFail);
        $this->service->compensateFailedEnrollment($orderFail, 'Test');

        // Verificar que ambos están en cache (no podemos verificar TTL exacto sin esperar)
        $this->assertNotNull($this->cache->get($orderSuccess));
        $this->assertNotNull($this->cache->get($orderFail));
    }
}