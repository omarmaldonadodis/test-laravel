<?php

namespace Tests\Feature;

use App\Jobs\CreateMoodleUserJob;
use App\Models\ProcessedWebhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MedusaWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        config(['services.medusa.medusa_webhook_secret' => 'test-secret']);
    }

    public function test_it_processes_valid_webhook_successfully()
    {
        Queue::fake();

        $payload = [
            'id' => 'order_test_123',
            'customer' => [
                'id' => 'cus_123',
                'email' => 'test@example.com',
                'first_name' => 'John',
                'last_name' => 'Doe',
            ],
            'items' => [
                [
                    'id' => 'item_123',
                    'title' => 'Test Product',
                    'product_id' => 'prod_123',
                    'variant_id' => 'var_123',
                    'quantity' => 1,
                    'unit_price' => 100,
                    'total' => 100,
                    'metadata' => [],
                ]
            ],
        ];

        $signature = hash_hmac('sha256', json_encode($payload), 'test-secret');

        $response = $this->postJson('/api/webhooks/medusa/order-paid', $payload, [
            'X-Medusa-Signature' => $signature,
        ]);

        $response->assertStatus(202);
        $response->assertJsonStructure([
            'message',
            'status',
            'data' => ['order_id', 'customer_email', 'queued_at'],
        ]);

        Queue::assertPushed(CreateMoodleUserJob::class);
    }

   
    public function test_it_rejects_webhook_with_invalid_signature()
    {
        $payload = [
            'id' => 'order_test_456',
            'customer' => [
                'id' => 'cus_456',
                'email' => 'test@example.com',
                'first_name' => 'Jane',
                'last_name' => 'Doe',
            ],
            'items' => [['id' => 'item_456']],
        ];

        $response = $this->postJson('/api/webhooks/medusa/order-paid', $payload, [
            'X-Medusa-Signature' => 'invalid-signature',
        ]);

        $response->assertStatus(401);
        $response->assertJsonFragment(['error' => 'Invalid signature']);
    }

  
    public function test_it_rejects_duplicate_webhook()
    {
        Queue::fake();

        // Crear webhook ya procesado
        ProcessedWebhook::create([
            'webhook_id' => 'wh_duplicate_123',
            'event_type' => 'order.paid',
            'medusa_order_id' => 'order_duplicate_123',
            'user_email' => 'duplicate@example.com',
            'payload' => ['test' => 'data'],
            'processed_at' => now(),
        ]);

        $payload = [
            'id' => 'order_duplicate_123',
            'customer' => [
                'id' => 'cus_123',
                'email' => 'duplicate@example.com',
                'first_name' => 'Duplicate',
                'last_name' => 'User',
            ],
            'items' => [['id' => 'item_123']],
        ];

        $signature = hash_hmac('sha256', json_encode($payload), 'test-secret');

        $response = $this->postJson('/api/webhooks/medusa/order-paid', $payload, [
            'X-Medusa-Signature' => $signature,
            'X-Webhook-Id' => 'wh_duplicate_123',
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['status' => 'duplicate']);
        
        // No debe despachar job
        Queue::assertNotPushed(CreateMoodleUserJob::class);
        }

       
    public function test_it_validates_required_customer_fields()
    {
        $payload = [
            'id' => 'order_invalid_123',
            'customer' => [
                'id' => 'cus_123',
                // Falta email, first_name, last_name
            ],
            'items' => [['id' => 'item_123']],
        ];

    $signature = hash_hmac('sha256', json_encode($payload), 'test-secret');

    $response = $this->postJson('/api/webhooks/medusa/order-paid', $payload, [
        'X-Medusa-Signature' => $signature,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['customer.email', 'customer.first_name', 'customer.last_name']);
}


public function test_it_validates_invalid_email_format()
{
    $payload = [
        'id' => 'order_invalid_email_123',
        'customer' => [
            'id' => 'cus_123',
            'email' => 'not-an-email',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ],
        'items' => [['id' => 'item_123']],
    ];

    $signature = hash_hmac('sha256', json_encode($payload), 'test-secret');

    $response = $this->postJson('/api/webhooks/medusa/order-paid', $payload, [
        'X-Medusa-Signature' => $signature,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['customer.email']);
}


public function test_it_requires_at_least_one_item()
{
    $payload = [
        'id' => 'order_no_items_123',
        'customer' => [
            'id' => 'cus_123',
            'email' => 'test@example.com',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ],
        'items' => [], // Sin items
    ];

    $signature = hash_hmac('sha256', json_encode($payload), 'test-secret');

    $response = $this->postJson('/api/webhooks/medusa/order-paid', $payload, [
        'X-Medusa-Signature' => $signature,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['items']);
}


public function test_it_handles_webhook_without_order_id()
{
    Queue::fake();

    $payload = [
        // Sin 'id' de orden
        'customer' => [
            'id' => 'cus_no_order_id',
            'email' => 'no-order-id@example.com',
            'first_name' => 'Test',
            'last_name' => 'User',
        ],
        'items' => [
            [
                'id' => 'item_123',
                'title' => 'Test Product',
                'metadata' => [],
            ]
        ],
    ];

    $signature = hash_hmac('sha256', json_encode($payload), 'test-secret');

    $response = $this->postJson('/api/webhooks/medusa/order-paid', $payload, [
        'X-Medusa-Signature' => $signature,
    ]);

    // Debería generar un order_id automáticamente
    $response->assertStatus(202);
    $response->assertJsonStructure([
        'data' => ['order_id', 'customer_email']
    ]);

    $responseData = $response->json('data');
    $this->assertNotEquals('unknown', $responseData['order_id']);
    $this->assertStringContainsString('ord_', $responseData['order_id']);
}


public function test_health_check_returns_ok_status()
{
    $response = $this->getJson('/api/webhooks/medusa/health');

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'status',
        'service',
        'timestamp',
    ]);
    $response->assertJsonFragment(['status' => 'ok']);
}


public function test_it_logs_webhook_processing()
{
    Queue::fake();
    \Log::spy();

    $payload = [
        'id' => 'order_logging_test',
        'customer' => [
            'id' => 'cus_123',
            'email' => 'logging@example.com',
            'first_name' => 'Log',
            'last_name' => 'Test',
        ],
        'items' => [['id' => 'item_123']],
    ];

    $signature = hash_hmac('sha256', json_encode($payload), 'test-secret');

    $this->postJson('/api/webhooks/medusa/order-paid', $payload, [
        'X-Medusa-Signature' => $signature,
    ]);

    \Log::shouldHaveReceived('info')
        ->with('📥 Webhook recibido', \Mockery::type('array'))
        ->once();
    }
    }