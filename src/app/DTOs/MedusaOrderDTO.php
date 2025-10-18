<?php

namespace App\DTOs;

use Illuminate\Support\Arr;


/**
 * DTO para encapsular datos de órdenes de Medusa
 * Principio: Single Responsibility - Solo maneja datos de órdenes
 */
class MedusaOrderDTO
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $customerEmail,
        public readonly string $customerFirstName,
        public readonly string $customerLastName,
        public readonly array $items,
        public readonly ?string $customerId = null,
        public readonly ?array $metadata = null
    ) {}

    /**
     * Crea una instancia desde el payload del webhook
     */
    public static function fromWebhookPayload(array $payload): self
    {
        return new self(
            orderId: Arr::get($payload, 'id', 'unknown'),
            customerEmail: Arr::get($payload, 'customer.email', ''),
            customerFirstName: Arr::get($payload, 'customer.first_name', 'Usuario'),
            customerLastName: Arr::get($payload, 'customer.last_name', 'Medusa'),
            items: Arr::get($payload, 'items', []),
            customerId: Arr::get($payload, 'customer.id'),
            metadata: Arr::get($payload, 'metadata', [])
        );
    }

    /**
     * Extrae IDs de cursos desde los items
     */
    public function getCourseIds(): array
    {
        return collect($this->items)
            ->pluck('metadata.moodle_course_id')
            ->filter()
            ->unique()
            ->values()
            ->toArray();
    }

    /**
     * Valida que el DTO tenga datos mínimos requeridos
     */
    public function isValid(): bool
    {
        return !empty($this->customerEmail) 
            && filter_var($this->customerEmail, FILTER_VALIDATE_EMAIL);
    }

    /**
     * Convierte el DTO a array para logs
     */
    public function toArray(): array
    {
        return [
            'order_id' => $this->orderId,
            'customer_email' => $this->customerEmail,
            'customer_name' => $this->getFullName(),
            'items_count' => count($this->items),
        ];
    }

    public function getFullName(): string
    {
        return trim("{$this->customerFirstName} {$this->customerLastName}");
    }
}