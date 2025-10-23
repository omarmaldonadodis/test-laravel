<?php

namespace Tests\Unit\Jobs;

use App\Contracts\MoodleServiceInterface;
use App\DTOs\MedusaOrderDTO;
use App\Events\MoodleUserCreated;
use App\Jobs\CreateMoodleUserJob;
use App\Exceptions\MoodleServiceException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class CreateMoodleUserJobTest extends TestCase
{
    use DatabaseTransactions;
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_creates_moodle_user_and_fires_event()
    {
        Event::fake();
        
        $orderDTO = new MedusaOrderDTO(
            orderId: 'order_123',
            customerEmail: 'test@example.com',
            customerFirstName: 'John',
            customerLastName: 'Doe',
            items: [],
        );

        $mockService = Mockery::mock(MoodleServiceInterface::class);
        $mockService->shouldReceive('generateUsername')
            ->once()
            ->with('test@example.com')
            ->andReturn('johndoe123');
        
        $mockService->shouldReceive('generatePassword')
            ->once()
            ->andReturn('SecurePass123!');
        
        $mockService->shouldReceive('createUser')
            ->once()
            ->andReturn([
                'id' => 42,
                'username' => 'johndoe123',
                'email' => 'test@example.com',
                'firstname' => 'John',
                'lastname' => 'Doe',
                'existing' => false,
            ]);

        $this->app->instance(MoodleServiceInterface::class, $mockService);

        $job = new CreateMoodleUserJob($orderDTO);
        $job->handle($mockService);

        Event::assertDispatched(MoodleUserCreated::class);
    }

    public function test_it_handles_invalid_order_data()
    {
        $orderDTO = new MedusaOrderDTO(
            orderId: 'order_456',
            customerEmail: 'invalid-email',
            customerFirstName: 'Jane',
            customerLastName: 'Doe',
            items: [],
        );

        $mockService = Mockery::mock(MoodleServiceInterface::class);

        // ✅ Simular que al intentar generar el username con un email inválido, lanza la excepción esperada
        $mockService->shouldReceive('generateUsername')
            ->once()
            ->with('invalid-email')
            ->andThrow(new MoodleServiceException('Invalid email'));

        // También puedes stubear los otros métodos para evitar que se llamen accidentalmente
        $mockService->shouldReceive('generatePassword')->never();
        $mockService->shouldReceive('createUser')->never();

        $this->app->instance(MoodleServiceInterface::class, $mockService);

        $this->expectException(MoodleServiceException::class);

        $job = new CreateMoodleUserJob($orderDTO);
        $job->handle($mockService);
    }


    public function test_it_retries_on_failure()
    {
        $job = new CreateMoodleUserJob(
            new MedusaOrderDTO(
                orderId: 'order_retry',
                customerEmail: 'retry@example.com',
                customerFirstName: 'Retry',
                customerLastName: 'Test',
                items: []
            )
        );

        $this->assertEquals(3, $job->tries);
        $this->assertEquals([60, 300, 900], $job->backoff);
    }

    public function test_it_has_correct_tags_for_horizon()
    {
        $orderDTO = new MedusaOrderDTO(
            orderId: 'order_tags_test',
            customerEmail: 'tags@example.com',
            customerFirstName: 'Tags',
            customerLastName: 'Test',
            items: []
        );

        $job = new CreateMoodleUserJob($orderDTO);
        $tags = $job->tags();

        $this->assertContains('moodle', $tags);
        $this->assertContains('user-creation', $tags);
        $this->assertContains('order:order_tags_test', $tags);
        $this->assertContains('email:tags@example.com', $tags);
    }

    public function test_it_calls_failed_method_on_final_failure()
    {
        Event::fake();
        
        $orderDTO = new MedusaOrderDTO(
            orderId: 'order_failed',
            customerEmail: 'failed@example.com',
            customerFirstName: 'Failed',
            customerLastName: 'Test',
            items: []
        );

        $job = new CreateMoodleUserJob($orderDTO);
        
        // Simular fallo
        $exception = new \Exception('Test failure');
        $job->failed($exception);

        // Verificar que no lanza excepción
        $this->assertTrue(true);
    }
}