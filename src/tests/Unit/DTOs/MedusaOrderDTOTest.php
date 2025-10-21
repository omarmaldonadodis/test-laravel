<?php

namespace Tests\Unit\DTOs;

use App\DTOs\MedusaOrderDTO;
use Tests\TestCase;

class MedusaOrderDTOTest extends TestCase
{
    /** @test */
    public function it_creates_dto_from_webhook_payload_with_order_id()
    {
        $payload = [
            'id' => 'order_123',
            'customer' => [
                'id' => 'cus_123',
                'email' => 'test@example.com',
                'first_name' => 'John',
                'last_name' => 'Doe',
            ],
            'items' => [
                [
                    'id' => 'item_123',
                    'metadata' => ['moodle_course_id' => 2],
                ]
            ],
            'metadata' => ['custom' => 'value'],
        ];

        $dto = MedusaOrderDTO::fromWebhookPayload($payload);

        $this->assertEquals('order_123', $dto->orderId);
        $this->assertEquals('test@example.com', $dto->customerEmail);
        $this->assertEquals('John', $dto->customerFirstName);
        $this->assertEquals('Doe', $dto->customerLastName);
        $this->assertEquals('cus_123', $dto->customerId);
        $this->assertIsArray($dto->items);
        $this->assertCount(1, $dto->items);
        $this->assertEquals(['custom' => 'value'], $dto->metadata);
    }

    /** @test */
    public function it_generates_order_id_from_customer_id_when_missing()
    {
        $payload = [
            // Sin 'id' de orden
            'customer' => [
                'id' => 'cus_456',
                'email' => 'test@example.com',
                'first_name' => 'Jane',
                'last_name' => 'Smith',
            ],
            'items' => [['id' => 'item_456']],
        ];

        $dto = MedusaOrderDTO::fromWebhookPayload($payload);

        $this->assertNotEquals('unknown', $dto->orderId);
        $this->assertStringContainsString('ord_cus_456', $dto->orderId);
    }

    /** @test */
    public function it_generates_unique_order_id_when_no_identifiers()
    {
        $payload = [
            'customer' => [
                'email' => 'test@example.com',
                'first_name' => 'Test',
                'last_name' => 'User',
            ],
            'items' => [['id' => 'item_789']],
        ];

        $dto = MedusaOrderDTO::fromWebhookPayload($payload);

        $this->assertNotEquals('unknown', $dto->orderId);
        $this->assertStringStartsWith('ord_', $dto->orderId);
    }

    /** @test */
    public function it_validates_dto_with_valid_email()
    {
        $payload = [
            'id' => 'order_valid',
            'customer' => [
                'id' => 'cus_123',
                'email' => 'valid@example.com',
                'first_name' => 'Valid',
                'last_name' => 'User',
            ],
            'items' => [['id' => 'item_123']],
        ];

        $dto = MedusaOrderDTO::fromWebhookPayload($payload);

        $this->assertTrue($dto->isValid());
    }

    /** @test */
    public function it_invalidates_dto_with_invalid_email()
    {
        $payload = [
            'id' => 'order_invalid',
            'customer' => [
                'id' => 'cus_123',
                'email' => 'not-an-email',
                'first_name' => 'Invalid',
                'last_name' => 'User',
            ],
            'items' => [['id' => 'item_123']],
        ];

        $dto = MedusaOrderDTO::fromWebhookPayload($payload);

        $this->assertFalse($dto->isValid());
    }

    /** @test */
    public function it_invalidates_dto_with_empty_email()
    {
        $payload = [
            'id' => 'order_empty_email',
            'customer' => [
                'id' => 'cus_123',
                'email' => '',
                'first_name' => 'Empty',
                'last_name' => 'Email',
            ],
            'items' => [['id' => 'item_123']],
        ];

        $dto = MedusaOrderDTO::fromWebhookPayload($payload);

        $this->assertFalse($dto->isValid());
    }

    /** @test */
    public function it_invalidates_dto_with_unknown_order_id()
    {
        $payload = [
            'customer' => [
                'email' => 'test@example.com',
                'first_name' => 'Test',
                'last_name' => 'User',
            ],
            'items' => [],
        ];

        $dto = new MedusaOrderDTO(
            orderId: 'unknown',
            customerEmail: 'test@example.com',
            customerFirstName: 'Test',
            customerLastName: 'User',
            items: []
        );

        $this->assertFalse($dto->isValid());
    }

    /** @test */
    public function it_extracts_course_ids_from_items()
    {
        $payload = [
            'id' => 'order_courses',
            'customer' => [
                'id' => 'cus_123',
                'email' => 'test@example.com',
                'first_name' => 'John',
                'last_name' => 'Doe',
            ],
            'items' => [
                ['id' => 'item_1', 'metadata' => ['moodle_course_id' => 2]],
                ['id' => 'item_2', 'metadata' => ['moodle_course_id' => 3]],
                ['id' => 'item_3', 'metadata' => []],
                ['id' => 'item_4', 'metadata' => ['moodle_course_id' => 2]], // Duplicado
            ],
        ];

        $dto = MedusaOrderDTO::fromWebhookPayload($payload);
        $courseIds = $dto->getCourseIds();

        $this->assertCount(2, $courseIds);
        $this->assertContains(2, $courseIds);
        $this->assertContains(3, $courseIds);
    }

    /** @test */
    public function it_returns_empty_array_when_no_course_ids()
    {
        $payload = [
            'id' => 'order_no_courses',
            'customer' => [
                'id' => 'cus_123',
                'email' => 'test@example.com',
                'first_name' => 'John',
                'last_name' => 'Doe',
            ],
            'items' => [
                ['id' => 'item_1', 'metadata' => []],
                ['id' => 'item_2'],
            ],
        ];

        $dto = MedusaOrderDTO::fromWebhookPayload($payload);
        $courseIds = $dto->getCourseIds();

        $this->assertIsArray($courseIds);
        $this->assertEmpty($courseIds);
    }

    /** @test */
    public function it_converts_dto_to_array()
    {
        $dto = new MedusaOrderDTO(
            orderId: 'order_123',
            customerEmail: 'test@example.com',
            customerFirstName: 'John',
            customerLastName: 'Doe',
            items: [['id' => 'item_1'], ['id' => 'item_2']],
            customerId: 'cus_123',
            metadata: ['key' => 'value']
        );

        $array = $dto->toArray();

        $this->assertIsArray($array);
        $this->assertEquals('order_123', $array['order_id']);
        $this->assertEquals('test@example.com', $array['customer_email']);
        $this->assertEquals('John Doe', $array['customer_name']);
        $this->assertEquals(2, $array['items_count']);
    }

    /** @test */
    public function it_gets_full_name()
    {
        $dto = new MedusaOrderDTO(
            orderId: 'order_123',
            customerEmail: 'test@example.com',
            customerFirstName: 'María José',
            customerLastName: 'García López',
            items: []
        );

        $fullName = $dto->getFullName();

        $this->assertEquals('María José García López', $fullName);
    }

    /** @test */
    public function it_trims_full_name()
    {
        $dto = new MedusaOrderDTO(
            orderId: 'order_123',
            customerEmail: 'test@example.com',
            customerFirstName: '  John  ',
            customerLastName: '  Doe  ',
            items: []
        );

        $fullName = $dto->getFullName();

        $this->assertEquals('John Doe', $fullName);
    }
}