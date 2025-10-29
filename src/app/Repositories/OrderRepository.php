<?php

namespace App\Repositories;

use App\Contracts\OrderRepositoryInterface;
use App\Models\Order;

class OrderRepository implements OrderRepositoryInterface
{
    public function findByMedusaId(string $medusaOrderId): ?Order
    {
        return Order::where('medusa_order_id', $medusaOrderId)->first();
    }

    public function create(array $data): Order
    {
        return Order::create($data);
    }

    public function markAsProcessed(Order $order, int $moodleUserId): Order
    {
        $order->update([
            'moodle_user_id' => $moodleUserId,
            'processed' => true,
            'processed_at' => now(),
        ]);
        
        return $order->fresh();
    }
}