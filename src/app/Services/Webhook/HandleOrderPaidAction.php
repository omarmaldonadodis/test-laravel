<?php

namespace App\Services\Webhook;

use App\Repositories\OrderRepository;
use App\Services\MoodleService;
use App\Services\EnrollmentService;
use Illuminate\Support\Facades\Log;
use Exception;

class HandleOrderPaidAction
{
    public function __construct(
        private MoodleService $moodleService,
        private OrderRepository $orderRepository,
        private EnrollmentService $enrollmentService
    ) {}

    public function execute(array $data): array
    {
        $orderId = $data['order_id'];

        if ($this->orderRepository->existsProcessed($orderId)) {
            return ['message' => 'Order already processed'];
        }

        $orderRecordId = $this->orderRepository->save([
            'medusa_order_id' => $orderId,
            'customer_email' => $data['customer']['email'],
            'customer_name' => trim($data['customer']['first_name'] . ' ' . $data['customer']['last_name']),
            'total' => $data['total'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $user = $this->moodleService->createOrFindUser($data['customer']);
            $enrolledCourses = $this->enrollmentService->enrollUserInCourses($user['id'], $data['items']);

            $this->orderRepository->update($orderRecordId, [
                'moodle_user_id' => $user['id'],
                'processed' => true,
                'processed_at' => now(),
            ]);

            return [
                'message' => 'Order processed successfully',
                'moodle_user_id' => $user['id'],
                'courses_enrolled' => $enrolledCourses
            ];

        } catch (Exception $e) {
            Log::error('Error handling order', ['error' => $e->getMessage()]);

            $this->orderRepository->update($orderRecordId, [
                'processed' => false,
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
