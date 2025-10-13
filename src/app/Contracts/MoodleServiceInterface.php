<?php

namespace App\Contracts;

/**
 * Contrato para servicios de Moodle
 * Principio: Interface Segregation + Dependency Inversion
 */
interface MoodleServiceInterface
{
    /**
     * Crea o recupera un usuario en Moodle
     */
    public function createUser(array $userData): ?array;

    /**
     * Busca usuario por email
     */
    public function getUserByEmail(string $email): ?array;

    /**
     * Inscribe usuario en curso
     */
    public function enrollUser(int $userId, int $courseId, int $roleId = 5): bool;

    /**
     * Verifica si usuario está inscrito
     */
    public function isUserEnrolled(int $userId, int $courseId): bool;

    /**
     * Genera username único
     */
    public function generateUsername(string $email): string;

    /**
     * Genera contraseña segura
     */
    public function generatePassword(int $length = 12): string;
}