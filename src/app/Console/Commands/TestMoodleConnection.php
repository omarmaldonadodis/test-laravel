<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\MoodleService;

class TestMoodleConnection extends Command
{
    protected $signature = 'test:moodle 
                            {--function=core_webservice_get_site_info : Moodle function to test}';
    
    protected $description = 'Test Moodle API connection and services';

    public function handle()
    {
        $moodleService = app(MoodleService::class);
        
        $this->info('🧪 Testing Moodle Connection...');
        $this->line('URL: ' . env('MOODLE_URL'));
        $this->line('Token: ' . (env('MOODLE_TOKEN') ? substr(env('MOODLE_TOKEN'), 0, 10) . '...' : 'NOT SET'));

        // Test basic connection
        $this->info("\n🔗 Testing basic connection...");
        $siteInfo = $moodleService->checkTokenPermissions();
        
        if ($siteInfo) {
            $this->info('✅ Basic connection successful!');
            $this->line('Site: ' . ($siteInfo['sitename'] ?? 'Unknown'));
            $this->line('Version: ' . ($siteInfo['version'] ?? 'Unknown'));
            $this->line('Username: ' . ($siteInfo['username'] ?? 'Unknown'));
            $this->line('User ID: ' . ($siteInfo['userid'] ?? 'Unknown'));
            $this->line('Available functions: ' . count($siteInfo['functions'] ?? []));
        } else {
            $this->error('❌ Basic connection failed');
            return;
        }

        // Test specific function
        $function = $this->option('function');
        if ($function !== 'core_webservice_get_site_info') {
            $this->info("\n🛠️ Testing function: {$function}");
            try {
                $result = $moodleService->callWebService($function);
                $this->info('✅ Function call successful!');
                $this->line('Result: ' . json_encode($result));
            } catch (\Exception $e) {
                $this->error('❌ Function call failed: ' . $e->getMessage());
            }
        }

        $this->info("\n📋 Check laravel.log for detailed logs");
    }
}