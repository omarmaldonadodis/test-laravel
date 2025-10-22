<?php

namespace Tests\Unit\Services\Compensation;

use App\Contracts\FailedEnrollmentRepositoryInterface;
use App\Contracts\MoodleServiceInterface;
use App\Services\Compensation\CompensationCache;
use App\Services\Compensation\MoodleCompensationService;
use PHPUnit\Framework\TestCase;
use Illuminate\Support\Facades\Log;

class MoodleCompensationServiceTest extends TestCase
{
    public function test_recordUserCreation_storesDataInCache()
    {
        $orderId = 'order123';
        $moodleUserId = 42;

        $cacheMock = $this->createMock(CompensationCache::class);
        $cacheMock->expects($this->once())
                  ->method('put')
                  ->with(
                      $orderId,
                      $this->callback(fn($data) => $data['moodle_user_id'] === $moodleUserId)
                  );

        $repoMock = $this->createMock(FailedEnrollmentRepositoryInterface::class);
        $moodleMock = $this->createMock(MoodleServiceInterface::class);

        $service = new MoodleCompensationService($moodleMock, $repoMock, $cacheMock);
        $service->recordUserCreation($moodleUserId, $orderId);
    }

    public function test_markEnrollmentSuccess_updatesCache()
    {
        $orderId = 'order123';
        $cacheMock = $this->createMock(CompensationCache::class);
        $cacheMock->method('get')->willReturn(['moodle_user_id' => 42, 'status' => 'pending_enrollment']);
        $cacheMock->expects($this->once())->method('put');

        $repoMock = $this->createMock(FailedEnrollmentRepositoryInterface::class);
        $moodleMock = $this->createMock(MoodleServiceInterface::class);

        $service = new MoodleCompensationService($moodleMock, $repoMock, $cacheMock);
        $service->markEnrollmentSuccess($orderId);
    }

    public function test_compensateFailedEnrollment_calls_repository_and_updates_cache()
    {
        $orderId = 'order123';
        $reason = 'Test failure';

        $cacheMock = $this->createMock(CompensationCache::class);
        $cacheMock->method('get')->willReturn(['moodle_user_id' => 42, 'status' => 'pending_enrollment']);
        $cacheMock->expects($this->once())->method('put');

        $repoMock = $this->createMock(FailedEnrollmentRepositoryInterface::class);
        $repoMock->expects($this->once())->method('create');

        $moodleMock = $this->createMock(MoodleServiceInterface::class);

        // <-- esto permite que el facade Log::warning no falle
        Log::shouldReceive('warning')->once();

        $service = new MoodleCompensationService($moodleMock, $repoMock, $cacheMock);
        $service->compensateFailedEnrollment($orderId, $reason);
    }

}
