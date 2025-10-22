<?php

namespace App\Repositories;

use App\Contracts\FailedEnrollmentRepositoryInterface;
use App\Models\FailedEnrollment;
use Illuminate\Support\Collection;

class FailedEnrollmentRepository implements FailedEnrollmentRepositoryInterface
{
    public function create(array $data): void
    {
        FailedEnrollment::create($data);
    }

    public function findPendingRetries(int $days = 7): Collection
    {
        return FailedEnrollment::where('requires_manual_review', true)
            ->where('created_at', '>', now()->subDays($days))
            ->get();
    }

    public function markResolved(string $orderId): void
    {
        FailedEnrollment::where('order_id', $orderId)
            ->update([
                'requires_manual_review' => false,
                'resolved_at' => now(),
            ]);
    }
}
