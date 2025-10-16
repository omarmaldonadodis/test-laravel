<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CreateApiToken extends Command
{
    protected $signature = 'token:create {email}';
    protected $description = 'Create API token for user';

    public function handle()
    {
        $email = $this->argument('email');
        $user = User::where('email', $email)->first();

        if (!$user) {
            $this->error("User not found");
            return;
        }

        $token = $user->createToken('api-token')->plainTextToken;
        $this->info("Token: {$token}");
    }
}