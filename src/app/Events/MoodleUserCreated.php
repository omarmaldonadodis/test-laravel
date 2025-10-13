<?php

namespace App\Events;

use App\DTOs\MedusaOrderDTO;
use App\DTOs\MoodleUserDTO;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento: Usuario creado en Moodle
 * Principio: Open/Closed - Permite extensión sin modificar código
 */
class MoodleUserCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly MoodleUserDTO $user,
        public readonly MedusaOrderDTO $order
    ) {}
}