<?php
namespace App\Services\Compensation;

class CompensationCache
{
    public function put(string $orderId, array $data, int $ttlHours = 24): void
    {
        cache()->put(
            "compensation:user:{$orderId}",
            $data,
            now()->addHours($ttlHours)
        );
    }

    public function get(string $orderId): ?array
    {
        return cache()->get("compensation:user:{$orderId}");
    }
}
