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
        $orderId = self::extractOrderId($payload);
        
        return new self(
            orderId: $orderId,
            customerEmail: Arr::get($payload, 'customer.email', ''),
            customerFirstName: Arr::get($payload, 'customer.first_name', 'Usuario'),
            customerLastName: Arr::get($payload, 'customer.last_name', 'Medusa'),
            items: Arr::get($payload, 'items', []),
            customerId: Arr::get($payload, 'customer.id'),
            metadata: Arr::get($payload, 'metadata', [])
        );
    }

    /**
     * Extrae el Order ID usando diferentes estrategias
     */
    private static function extractOrderId(array $payload): string
    {
        // Estrategia 1: Buscar 'id' en raíz
        if ($id = Arr::get($payload, 'id')) {
            return $id;
        }

        // Estrategia 2: Buscar 'order_id' en raíz
        if ($orderId = Arr::get($payload, 'order_id')) {
            return $orderId;
        }

        // Estrategia 3: Buscar en metadata
        if ($orderId = Arr::get($payload, 'metadata.order_id')) {
            return $orderId;
        }

        // Estrategia 4: Generar desde customer ID + timestamp
        $customerId = Arr::get($payload, 'customer.id');
        if ($customerId) {
            return "ord_{$customerId}_" . now()->timestamp;
        }

        // Estrategia 5: Usar el ID del primer item
        $firstItem = Arr::get($payload, 'items.0');
        if ($firstItem && isset($firstItem['id'])) {
            return "ord_from_item_" . $firstItem['id'];
        }

        // Fallback: Generar ID único
        return 'ord_' . uniqid();
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
        return !empty(trim($this->customerEmail))
            && filter_var($this->customerEmail, FILTER_VALIDATE_EMAIL)
            && !empty($this->orderId)
            && $this->orderId !== 'unknown';
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
        $firstName = trim($this->customerFirstName);
        $lastName = trim($this->customerLastName);
        
        return trim("{$firstName} {$lastName}");
    }
}