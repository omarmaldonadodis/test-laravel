<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ListMoodleServices extends Command
{
    protected $signature = 'moodle:list-services';
    protected $description = 'List all available Moodle web services';

    public function handle()
    {
        $this->info('📋 LISTING ALL MOODLE WEB SERVICES');
        
        $url = env('MOODLE_URL') . '/webservice/rest/server.php';
        $token = env('MOODLE_TOKEN');
        
        $this->line('URL: ' . env('MOODLE_URL'));
        $this->line('Token: ' . ($token ? substr($token, 0, 10) . '...' : 'NOT SET'));

        // Primero verificar conexión básica
        $this->info("\n🔗 Testing connection...");
        
        try {
            $response = Http::timeout(10)
                ->asForm()
                ->post($url, [
                    'wstoken' => $token,
                    'wsfunction' => 'core_webservice_get_site_info',
                    'moodlewsrestformat' => 'json'
                ]);
                
            if (!$response->successful()) {
                $this->error('❌ Connection failed: ' . $response->status());
                $this->line('Response: ' . $response->body());
                return;
            }
            
            $data = $response->json();
            
            // DEBUG: Mostrar estructura completa para entender el formato
            $this->info("\n🔍 DEBUG DATA STRUCTURE:");
            $this->line('Data type: ' . gettype($data));
            $this->line('Has exception: ' . (isset($data['exception']) ? 'YES' : 'NO'));
            
            if (isset($data['exception'])) {
                $this->error('❌ Token error: ' . $data['message']);
                return;
            }
            
            $this->info('✅ Connection successful!');
            $this->line('Site: ' . ($data['sitename'] ?? 'Unknown'));
            $this->line('User: ' . ($data['username'] ?? 'Unknown'));
            
            // Mostrar estructura de functions para debug
            if (isset($data['functions'])) {
                $this->line('Functions type: ' . gettype($data['functions']));
                if (is_array($data['functions'])) {
                    $this->line('Functions count: ' . count($data['functions']));
                    if (count($data['functions']) > 0) {
                        $firstFunc = $data['functions'][0];
                        $this->line('First function type: ' . gettype($firstFunc));
                        $this->line('First function: ' . json_encode($firstFunc));
                    }
                }
            }
            
        } catch (\Exception $e) {
            $this->error('❌ Connection exception: ' . $e->getMessage());
            return;
        }

        // Procesar funciones basado en la estructura real
        $this->info("\n📊 PROCESSING FUNCTIONS:");
        
        if (!isset($data['functions']) || !is_array($data['functions'])) {
            $this->error('No functions array found in response');
            return;
        }

        // Extraer nombres de funciones
        $functionNames = [];
        foreach ($data['functions'] as $function) {
            if (is_string($function)) {
                $functionNames[] = $function;
            } elseif (is_array($function) && isset($function['name'])) {
                $functionNames[] = $function['name'];
            } else {
                // Si es un array sin name, usar el primer elemento
                $functionNames[] = is_array($function) ? json_encode($function) : (string)$function;
            }
        }

        $this->line('Total functions found: ' . count($functionNames));

        // Mostrar funciones esenciales
        $this->info("\n🎯 ESSENTIAL FUNCTIONS FOR LARAVEL INTEGRATION:");
        
        $essentialFunctions = [
            'core_user_create_users',
            'core_user_get_users_by_field',
            'enrol_manual_enrol_users',
            'core_enrol_get_users_courses',
            'core_webservice_get_site_info'
        ];
        
        foreach ($essentialFunctions as $function) {
            if (in_array($function, $functionNames)) {
                $this->line("✅ $function");
            } else {
                $this->error("❌ $function - MISSING");
            }
        }

        // Agrupar funciones por categoría (usando los nombres extraídos)
        $categories = [
            'user' => [],
            'enrollment' => [],
            'course' => [],
            'web_service' => [],
            'other' => []
        ];
        
        foreach ($functionNames as $functionName) {
            if (str_contains($functionName, 'core_user') || str_contains($functionName, 'auth_') || str_contains($functionName, 'user')) {
                $categories['user'][] = $functionName;
            } elseif (str_contains($functionName, 'enrol') || str_contains($functionName, 'enrollment')) {
                $categories['enrollment'][] = $functionName;
            } elseif (str_contains($functionName, 'core_course') || str_contains($functionName, 'course')) {
                $categories['course'][] = $functionName;
            } elseif (str_contains($functionName, 'core_webservice') || str_contains($functionName, 'webservice')) {
                $categories['web_service'][] = $functionName;
            } else {
                $categories['other'][] = $functionName;
            }
        }

        // Mostrar funciones por categoría
        $this->info("\n👤 USER FUNCTIONS (" . count($categories['user']) . "):");
        foreach (array_slice($categories['user'], 0, 15) as $function) {
            $this->line("• $function");
        }
        if (count($categories['user']) > 15) {
            $this->line("... and " . (count($categories['user']) - 15) . " more");
        }

        $this->info("\n🎓 ENROLLMENT FUNCTIONS (" . count($categories['enrollment']) . "):");
        foreach ($categories['enrollment'] as $function) {
            $this->line("• $function");
        }

        $this->info("\n📚 COURSE FUNCTIONS (" . count($categories['course']) . "):");
        foreach (array_slice($categories['course'], 0, 10) as $function) {
            $this->line("• $function");
        }

        $this->info("\n🌐 WEB SERVICE FUNCTIONS (" . count($categories['web_service']) . "):");
        foreach ($categories['web_service'] as $function) {
            $this->line("• $function");
        }

        $this->info("\n🔧 OTHER FUNCTIONS (" . count($categories['other']) . "):");
        foreach (array_slice($categories['other'], 0, 10) as $function) {
            $this->line("• $function");
        }
        if (count($categories['other']) > 10) {
            $this->line("... and " . (count($categories['other']) - 10) . " more");
        }

        $this->info("\n💡 SUMMARY:");
        $this->line("Total functions available: " . count($functionNames));
        
        $missingEssential = array_diff($essentialFunctions, $functionNames);
        if (empty($missingEssential)) {
            $this->info("✅ All essential functions are available!");
            $this->line("Your token has the correct permissions.");
        } else {
            $this->error("❌ Missing " . count($missingEssential) . " essential functions:");
            foreach ($missingEssential as $missing) {
                $this->line("  - $missing");
            }
            $this->line("\nYou need to add these functions to your service in Moodle.");
        }
    }
}