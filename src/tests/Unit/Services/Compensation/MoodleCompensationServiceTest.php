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

        // ✅ Pasar defaultCourseId para evitar config()
        $service = new MoodleCompensationService($moodleMock, $repoMock, $cacheMock, 2);
        
        // ✅ Mock Log facade
        Log::shouldReceive('info')->once();
        
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

        // ✅ Pasar defaultCourseId
        $service = new MoodleCompensationService($moodleMock, $repoMock, $cacheMock, 2);
        
        // ✅ Mock Log facade
        Log::shouldReceive('info')->once();
        
        $service->markEnrollmentSuccess($orderId);
    }

}