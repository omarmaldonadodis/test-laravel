<?php

namespace App\Constants;

/**
 * Constantes de roles de Moodle
 * Basado en: https://docs.moodle.org/dev/Roles
 */
class MoodleRoles
{
    // Roles estándar de Moodle
    public const MANAGER = 1;
    public const COURSE_CREATOR = 2;
    public const TEACHER = 3;
    public const NON_EDITING_TEACHER = 4;
    public const STUDENT = 5; // ✅ Rol por defecto
    public const GUEST = 6;
    public const AUTHENTICATED_USER = 7;
    public const AUTHENTICATED_USER_ON_SITE_FRONTPAGE = 8;

    /**
     * Obtiene el nombre legible del rol
     */
    public static function getName(int $roleId): string
    {
        return match($roleId) {
            self::MANAGER => 'Manager',
            self::COURSE_CREATOR => 'Course Creator',
            self::TEACHER => 'Teacher',
            self::NON_EDITING_TEACHER => 'Non-editing Teacher',
            self::STUDENT => 'Student',
            self::GUEST => 'Guest',
            self::AUTHENTICATED_USER => 'Authenticated User',
            self::AUTHENTICATED_USER_ON_SITE_FRONTPAGE => 'Authenticated User on Site Frontpage',
            default => "Unknown Role ({$roleId})",
        };
    }

    /**
     * Valida si un rol es válido
     */
    public static function isValid(int $roleId): bool
    {
        return in_array($roleId, [
            self::MANAGER,
            self::COURSE_CREATOR,
            self::TEACHER,
            self::NON_EDITING_TEACHER,
            self::STUDENT,
            self::GUEST,
            self::AUTHENTICATED_USER,
            self::AUTHENTICATED_USER_ON_SITE_FRONTPAGE,
        ]);
    }
}