<?php

namespace Tests\Unit\Jobs;

use App\Contracts\MoodleServiceInterface;
use App\DTOs\MedusaOrderDTO;
use App\Events\MoodleUserCreated;
use App\Jobs\CreateMoodleUserJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

class CreateMoodleUserJobTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    
    public function test_it_creates_moodle_user_and_fires_event(): void
    {
        // Arrange
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

        // Act
        $job = new CreateMoodleUserJob($orderDTO);
        $job->handle($mockService);

        // Assert
        Event::assertDispatched(MoodleUserCreated::class);
    }

    public function test_it_handles_invalid_order_data(): void
    {
        // Arrange
        $orderDTO = new MedusaOrderDTO(
            orderId: 'order_456',
            customerEmail: 'invalid-email', // Email inválido
            customerFirstName: 'Jane',
            customerLastName: 'Doe',
            items: [],
        );

        $mockService = Mockery::mock(MoodleServiceInterface::class);
        $this->app->instance(MoodleServiceInterface::class, $mockService);

        // Act & Assert
        $this->expectException(\App\Exceptions\MoodleServiceException::class);
        
        $job = new CreateMoodleUserJob($orderDTO);
        $job->handle($mockService);
    }
}