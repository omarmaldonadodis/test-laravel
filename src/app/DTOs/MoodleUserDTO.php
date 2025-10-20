<?php

namespace App\DTOs;

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

    public static function fromMoodleResponse(array $data): self
    {
        return new self(
            email: $data['email'],
            firstname: $data['firstname'] ?? '',
            lastname: $data['lastname'] ?? '',
            username: $data['username'],
            password: '',
            id: $data['id'],
            existing: $data['existing'] ?? false
        );
    }

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

    public function getFullName(): string
    {
        return trim($this->firstname . ' ' . $this->lastname);
    }

    public function isValid(): bool
    {
        return !empty($this->email) 
            && filter_var($this->email, FILTER_VALIDATE_EMAIL)
            && !empty($this->firstname)
            && !empty($this->lastname)
            && !empty($this->username);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'username' => $this->username,
            'firstname' => $this->firstname,
            'lastname' => $this->lastname,
            'full_name' => $this->getFullName(),
            'existing' => $this->existing,
        ];
    }
}
