<?php

namespace App\Contracts;

use App\Models\Order;

interface OrderRepositoryInterface
{
    public function findByMedusaId(string $medusaOrderId): ?Order;
    
    public function create(array $data): Order;
    
    public function markAsProcessed(Order $order, int $moodleUserId): Order;
}