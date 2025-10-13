<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

class OrderRepository
{
    public function findByMedusaId(string $medusaOrderId)
    {
        return DB::table('orders')->where('medusa_order_id', $medusaOrderId)->first();
    }

    public function save(array $data): int
    {
        return DB::table('orders')->insertGetId($data);
    }

    public function update(int $id, array $data): void
    {
        DB::table('orders')->where('id', $id)->update($data);
    }

    public function existsProcessed(string $orderId): bool
    {
        return DB::table('orders')
            ->where('medusa_order_id', $orderId)
            ->where('processed', true)
            ->exists();
    }
}
