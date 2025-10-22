<?php
namespace App\Contracts;

use Illuminate\Support\Collection;

interface FailedEnrollmentRepositoryInterface
{
    public function create(array $data): void;
    public function findPendingRetries(int $days = 7): Collection;
    public function markResolved(string $orderId): void;
}
