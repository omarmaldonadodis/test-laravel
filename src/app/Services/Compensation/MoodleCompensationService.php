<?php

namespace App\Services\Compensation;

use App\Contracts\MoodleServiceInterface;
use App\Contracts\FailedEnrollmentRepositoryInterface;
use Illuminate\Support\Facades\Log;

class MoodleCompensationService
{
    public function __construct(
        private MoodleServiceInterface $moodleService,
        private FailedEnrollmentRepositoryInterface $repository,
        private CompensationCache $cache
    ) {}

    public function recordUserCreation(int $moodleUserId, string $orderId): void
    {
        $data = $this->cache->get($orderId) ?? [];
        $data = array_merge($data, [
            'moodle_user_id' => $moodleUserId,
            'status' => 'pending_enrollment',
            'created_at' => $data['created_at'] ?? now()->toIso8601String(),
        ]);

        $this->cache->put($orderId, $data, 24); // 24h
        Log::info('📝 User creation recorded for compensation', compact('orderId', 'moodleUserId'));
    }

    public function markEnrollmentSuccess(string $orderId): void
    {
        $data = $this->cache->get($orderId);
        if ($data) {
            $data = array_merge($data, [
                'status' => 'completed',
                'completed_at' => now()->toIso8601String(),
            ]);
            $this->cache->put($orderId, $data, 168); // 7 días
            Log::info('✅ Enrollment marked as completed', compact('orderId'));
        }
    }


    public function compensateFailedEnrollment(string $orderId, string $reason): void
    {
        $data = $this->cache->get($orderId);
        if (!$data) {
            Log::warning('⚠️ No compensation data found', compact('orderId'));
            return;
        }

        $existing = \App\Models\FailedEnrollment::where('order_id', $orderId)->first();
        if ($existing) {
            Log::info('ℹ️ Enrollment already compensated', compact('orderId'));
            return;
        }

        $this->repository->create([
            'order_id' => $orderId,
            'moodle_user_id' => $data['moodle_user_id'],
            'failure_reason' => $reason,
            'requires_manual_review' => true,
            'user_data' => $data,
        ]);

        $data['status'] = 'failed';
        $data['failed_at'] = now()->toIso8601String();
        $data['failure_reason'] = $reason;
        $this->cache->put($orderId, $data, 720); // 30 días

        Log::warning('Enrollment failed - User created but not enrolled', [
            'order_id' => $orderId,
            'moodle_user_id' => $data['moodle_user_id'],
            'reason' => $reason,
            'action_required' => 'Manual review required',
        ]);
    }

    public function retryFailedEnrollments(): int
    {
        $failed = $this->repository->findPendingRetries();
        $retried = 0;

        foreach ($failed as $failure) {
            try {
                $courseId = config('services.moodle.default_course_id', 2);
                $this->moodleService->enrollUser($failure->moodle_user_id, $courseId);

                $this->repository->markResolved($failure->order_id);
                $this->markEnrollmentSuccess($failure->order_id);
                $retried++;

                Log::info('Enrollment retry successful', [
                    'order_id' => $failure->order_id,
                    'moodle_user_id' => $failure->moodle_user_id,
                ]);
            } catch (\Exception $e) {
                Log::error('Enrollment retry failed', [
                    'order_id' => $failure->order_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $retried;
    }
}
