<?php

namespace App\DTOs;

/**
 * DTO para datos de usuario de Moodle
 * Principio: Single Responsibility - Solo maneja datos de usuarios Moodle
 */
class MoodleUserDTO
{
    public function __construct(
        public readonly string $email,
        public readonly string $firstname,
        public readonly string $lastname,
        public readonly string $username,
        public readonly string $password,
        public readonly ?int $id = null,
        public readonly bool $existing = false
    ) {}

    /**
     * Crea desde MedusaOrderDTO
     */
    public static function fromMedusaOrder(MedusaOrderDTO $order, string $username, string $password): self
    {
        return new self(
            email: $order->customerEmail,
            firstname: $order->customerFirstName,
            lastname: $order->customerLastName,
            username: $username,
            password: $password
        );
    }

    /**
     * Crea desde respuesta de Moodle
     */
    public static function fromMoodleResponse(array $data): self
    {
        return new self(
            email: $data['email'],
            firstname: $data['firstname'] ?? '',
            lastname: $data['lastname'] ?? '',
            username: $data['username'],
            password: '', // No se devuelve en respuestas
            id: $data['id'],
            existing: $data['existing'] ?? false
        );
    }

    /**
     * Convierte a formato para crear usuario en Moodle
     */
    public function toMoodleCreateFormat(): array
    {
        return [
            'username' => $this->username,
            'password' => $this->password,
            'firstname' => $this->firstname,
            'lastname' => $this->lastname,
            'email' => $this->email,
        ];
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'username' => $this->username,
            'existing' => $this->existing,
        ];
    }
}