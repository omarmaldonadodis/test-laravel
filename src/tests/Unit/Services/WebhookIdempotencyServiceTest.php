<?php

namespace Tests\Unit\Services;

use App\Services\Webhook\WebhookIdempotencyService;
use App\Models\ProcessedWebhook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookIdempotencyServiceTest extends TestCase
{
    use RefreshDatabase;

    private WebhookIdempotencyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WebhookIdempotencyService();
    }

    /** @test */
    public function it_allows_new_webhook_to_be_processed()
    {
        $result = $this->service->canProcessWebhook(
            'wh_new_123',
            'order_new_123',
            'new@example.com'
        );

        $this->assertTrue($result['can_process']);
        $this->assertEquals('new_webhook', $result['reason']);
    }

    /** @test */
    public function it_rejects_duplicate_webhook_id()
    {
        ProcessedWebhook::create([
            'webhook_id' => 'wh_duplicate',
            'event_type' => 'order.paid',
            'medusa_order_id' => 'order_123',
            'user_email' => 'test@example.com',
            'payload' => ['data' => 'test'],
            'processed_at' => now(),
        ]);

        $result = $this->service->canProcessWebhook(
            'wh_duplicate',
            'order_456',
            'test@example.com'
        );

        $this->assertFalse($result['can_process']);
        $this->assertEquals('duplicate_webhook', $result['reason']);
    }

    /** @test */
    public function it_rejects_duplicate_order_id()
    {
        ProcessedWebhook::create([
            'webhook_id' => 'wh_123',
            'event_type' => 'order.paid',
            'medusa_order_id' => 'order_duplicate',
            'user_email' => 'test@example.com',
            'payload' => ['data' => 'test'],
            'processed_at' => now(),
        ]);

        $result = $this->service->canProcessWebhook(
            'wh_456',
            'order_duplicate',
            'test@example.com'
        );

        $this->assertFalse($result['can_process']);
        $this->assertEquals('duplicate_order', $result['reason']);
    }

    /** @test */
    public function it_detects_existing_moodle_user()
    {
        $user = User::factory()->create([
            'email' => 'existing@example.com',
            'moodle_user_id' => 999,
        ]);

        $result = $this->service->canProcessWebhook(
            'wh_789',
            'order_789',
            'existing@example.com'
        );

        $this->assertFalse($result['can_process']);
        $this->assertEquals('user_exists', $result['reason']);
        $this->assertInstanceOf(User::class, $result['user']);
        $this->assertEquals(999, $result['user']->moodle_user_id);
    }

    /** @test */
    public function it_marks_webhook_as_processed()
    {
        $this->service->markWebhookAsProcessed(
            'wh_mark_test',
            'order_mark_test',
            ['test' => 'data'],
            'order.paid'
        );

        $this->assertDatabaseHas('processed_webhooks', [
            'webhook_id' => 'wh_mark_test',
            'medusa_order_id' => 'order_mark_test',
            'event_type' => 'order.paid',
        ]);
    }

    /** @test */
    public function it_checks_and_marks_in_atomic_transaction()
    {
        $payload = ['test' => 'data'];

        $result = $this->service->checkAndMark(
            'wh_atomic',
            'order_atomic',
            $payload
        );

        $this->assertTrue($result);
        $this->assertDatabaseHas('processed_webhooks', [
            'webhook_id' => 'wh_atomic',
            'medusa_order_id' => 'order_atomic',
        ]);
    }

    /** @test */
    public function it_prevents_duplicate_processing_in_check_and_mark()
    {
        $payload = ['test' => 'data'];

        // Primera llamada - debe procesar
        $result1 = $this->service->checkAndMark('wh_check', 'order_check', $payload);
        
        // Segunda llamada - debe rechazar
        $result2 = $this->service->checkAndMark('wh_check', 'order_check', $payload);

        $this->assertTrue($result1);
        $this->assertFalse($result2);
    }

    /** @test */
    public function it_links_order_to_existing_user()
    {
        $user = User::factory()->create([
            'email' => 'link@example.com',
            'moodle_user_id' => 123,
            'medusa_order_id' => null,
        ]);

        $this->service->linkOrderToExistingUser($user, 'order_link_123');

        $user->refresh();
        $this->assertEquals('order_link_123', $user->medusa_order_id);
        $this->assertNotNull($user->moodle_processed_at);
    }

    /** @test */
    public function it_handles_race_condition_with_atomic_operations()
    {
        $payload = ['test' => 'race'];

        // Simular dos llamadas concurrentes
        $results = [];
        for ($i = 0; $i < 2; $i++) {
            $results[] = $this->service->checkAndMark(
                'wh_race',
                'order_race',
                $payload
            );
        }

        // Solo una debe tener éxito
        $successCount = count(array_filter($results, fn($r) => $r === true));
        $this->assertEquals(1, $successCount);

        // Debe haber solo un registro
        $count = ProcessedWebhook::where('webhook_id', 'wh_race')->count();
        $this->assertEquals(1, $count);
    }
}