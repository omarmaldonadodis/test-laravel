<?php

namespace App\Exceptions;

use Exception;
use Throwable;

/**
 * Excepción para errores específicos de Moodle
 */
class MoodleServiceException extends Exception
{
    protected array $context = [];

    public function __construct(
        string $message = "",
        int $code = 0,
        ?Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    public static function connectionFailed(string $reason, array $context = []): self
    {
        return new self(
            "Failed to connect to Moodle: {$reason}",
            500,
            null,
            array_merge(['type' => 'connection_error'], $context)
        );
    }

    public static function userCreationFailed(string $email, string $reason, array $context = []): self
    {
        return new self(
            "Failed to create user {$email}: {$reason}",
            422,
            null,
            array_merge(['type' => 'user_creation_error', 'email' => $email], $context)
        );
    }

    public static function enrollmentFailed(int $userId, int $courseId, string $reason): self
    {
        return new self(
            "Failed to enroll user {$userId} in course {$courseId}: {$reason}",
            422,
            null,
            ['type' => 'enrollment_error', 'user_id' => $userId, 'course_id' => $courseId]
        );
    }

    public function getContext(): array
    {
        return $this->context;
    }
}