<?php
namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CreateApiToken extends Command
{
    protected $signature = 'api:create-token 
                            {email : Email del usuario}
                            {name=api-token : Nombre del token}';
    
    protected $description = 'Crea un API token para un usuario';

    public function handle(): int
    {
        $email = $this->argument('email');
        $tokenName = $this->argument('name');

        $user = User::where('email', $email)->first();

        if (!$user) {
            $this->error("❌ Usuario no encontrado: {$email}");
            return 1;
        }

        // Crear token
        $token = $user->createToken($tokenName)->plainTextToken;

        $this->info("✅ Token creado exitosamente:");
        $this->line($token);
        $this->newLine();
        $this->info("🔐 Guarda este token de forma segura.");
        $this->info("📝 Úsalo en el header: Authorization: Bearer {token}");

        return 0;
    }
}
