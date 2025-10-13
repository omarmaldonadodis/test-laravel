<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TestMoodleDirect extends Command
{
    protected $signature = 'test:moodle-direct';
    protected $description = 'Test Moodle user creation with direct API call';

    public function handle()
    {
        $url = env('MOODLE_URL') . '/webservice/rest/server.php';
        $token = env('MOODLE_TOKEN');
        $email = 'test_' . time() . '@example.com';
        $username = 'testuser' . time();
        $password = 'Test123!@#';

        $this->info('🧪 Testing Moodle User Creation Directly...');
        $this->line("URL: " . $url);
        $this->line("Token: " . substr($token, 0, 10) . '...');
        $this->line("Email: " . $email);
        $this->line("Username: " . $username);

        $params = [
            'wstoken' => $token,
            'wsfunction' => 'core_user_create_users',
            'moodlewsrestformat' => 'json',
            'users[0][username]' => $username,
            'users[0][password]' => $password,
            'users[0][firstname]' => 'Test',
            'users[0][lastname]' => 'User',
            'users[0][email]' => $email,
            'users[0][auth]' => 'manual',
        ];

        try {
            $response = Http::timeout(30)->asForm()->post($url, $params);
            
            $this->line("Status: " . $response->status());
            $this->line("Full Response: " . $response->body());

            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['exception'])) {
                    $this->error("❌ Moodle Error: " . $data['message']);
                    if (isset($data['debuginfo'])) {
                        $this->line("Debug: " . $data['debuginfo']);
                    }
                } else if (isset($data[0]['id'])) {
                    $this->info("✅ User created successfully! ID: " . $data[0]['id']);
                } else {
                    $this->error("❌ Unexpected response format");
                }
            } else {
                $this->error("❌ HTTP Error: " . $response->status());
            }

        } catch (\Exception $e) {
            $this->error("❌ Exception: " . $e->getMessage());
        }
    }
}