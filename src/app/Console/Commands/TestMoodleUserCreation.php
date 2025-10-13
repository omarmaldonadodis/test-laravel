<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\MoodleService;

class TestMoodleUserCreation extends Command
{
    protected $signature = 'test:moodle-user 
                            {email=test@example.com : User email}
                            {--firstname=Test : First name}
                            {--lastname=User : Last name}';
    
    protected $description = 'Test Moodle user creation specifically';

    public function handle()
    {
        $moodleService = app(MoodleService::class);
        
        $email = $this->argument('email');
        $firstname = $this->option('firstname');
        $lastname = $this->option('lastname');
        $username = $moodleService->generateUsername($email);
        $password = $moodleService->generatePassword();

        $this->info('🧪 Testing Moodle User Creation...');
        $this->line("Email: {$email}");
        $this->line("Name: {$firstname} {$lastname}");
        $this->line("Username: {$username}");

        // Test connection first
        $this->info("\n🔗 Testing connection...");
        $siteInfo = $moodleService->checkTokenPermissions();
        
        if (!$siteInfo) {
            $this->error('❌ Connection failed');
            return;
        }

        $this->info('✅ Connection successful');
        $this->line("Site: " . ($siteInfo['sitename'] ?? 'Unknown'));
        $this->line("User: " . ($siteInfo['username'] ?? 'Unknown'));

        // Test user creation
        $this->info("\n👤 Testing user creation...");
        
        $user = $moodleService->createUser([
            'username' => $username,
            'password' => $password,
            'firstname' => $firstname,
            'lastname' => $lastname,
            'email' => $email,
        ]);

        if ($user) {
            $this->info('✅ User creation successful!');
            $this->line("User ID: " . $user['id']);
            $this->line("Existing: " . ($user['existing'] ? 'Yes' : 'No'));
            
            if (!$user['existing']) {
                $this->line("Password: {$password}");
            }

            // Test enrollment
            $this->info("\n🎓 Testing enrollment in default course (ID 2)...");
            $enrollmentSuccess = $moodleService->enrollUser($user['id'], 2);
            
            if ($enrollmentSuccess) {
                $this->info('✅ Enrollment successful!');
            } else {
                $this->error('❌ Enrollment failed');
            }
        } else {
            $this->error('❌ User creation failed');
        }

        $this->info("\n📋 Check laravel.log for detailed logs");
    }
}