<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\MoodleService;

class CheckMoodlePermissions extends Command
{
    protected $signature = 'check:moodle-permissions';
    protected $description = 'Check Moodle service permissions';

    public function handle()
    {
        $moodleService = app(MoodleService::class);
        
        $this->info('🔐 Checking Moodle Permissions...');
        
        $siteInfo = $moodleService->checkTokenPermissions();
        
        if (!$siteInfo) {
            $this->error('❌ Cannot connect to Moodle');
            return;
        }

        $this->info('✅ Connected to Moodle');
        $this->line("Site: {$siteInfo['sitename']}");
        $this->line("User: {$siteInfo['username']}");
        $this->line("Available functions: " . count($siteInfo['functions'] ?? []));
        
        // Check for required functions
        $requiredFunctions = [
            'core_user_create_users',
            'core_user_get_users_by_field',
            'enrol_manual_enrol_users',
            'core_enrol_get_users_courses'
        ];
        
        $this->info("\n🔍 Checking required functions:");
        
        foreach ($requiredFunctions as $function) {
            if (in_array($function, $siteInfo['functions'] ?? [])) {
                $this->line("✅ {$function}");
            } else {
                $this->error("❌ {$function} - MISSING");
            }
        }
    }
}