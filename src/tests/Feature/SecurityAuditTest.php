<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityAuditTest extends TestCase
{
    /**
     * Test: Verificar que no existan contraseñas hardcodeadas en el código
     */
    public function test_no_hardcoded_passwords_in_codebase()
    {
        $directories = [
            app_path(),
            base_path('routes'),
            database_path('migrations'),
        ];

        $suspiciousPatterns = [
            "/password['\"]?\s*[:=]\s*['\"][^'\"]{5,}['\"]/i",
            "/Medusa2025/",
            "/'password'\s*=>\s*'[^']+'/",
        ];

        $foundIssues = [];

        foreach ($directories as $directory) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory)
            );

            foreach ($files as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $content = file_get_contents($file->getRealPath());

                    foreach ($suspiciousPatterns as $pattern) {
                        if (preg_match($pattern, $content, $matches)) {
                            // Excepciones permitidas
                            if (
                                str_contains($content, 'bcrypt(') ||
                                str_contains($content, 'Hash::make') ||
                                str_contains($content, 'Str::random')
                            ) {
                                continue;
                            }

                            $foundIssues[] = [
                                'file' => $file->getRealPath(),
                                'match' => $matches[0],
                            ];
                        }
                    }
                }
            }
        }

        $this->assertEmpty(
            $foundIssues,
            "Contraseñas hardcodeadas encontradas:\n" . 
            json_encode($foundIssues, JSON_PRETTY_PRINT)
        );
    }

    /**
     * Test: Verificar que las contraseñas generadas sean seguras
     */
    public function test_generated_passwords_are_secure()
    {
        $moodleService = app(\App\Contracts\MoodleServiceInterface::class);
        
        $password = $moodleService->generatePassword(12);

        // Debe tener al menos 12 caracteres
        $this->assertGreaterThanOrEqual(12, strlen($password));

        // Debe contener letra minúscula
        $this->assertMatchesRegularExpression('/[a-z]/', $password);

        // Debe contener letra mayúscula
        $this->assertMatchesRegularExpression('/[A-Z]/', $password);

        // Debe contener número
        $this->assertMatchesRegularExpression('/[0-9]/', $password);

        // Debe contener carácter especial
        $this->assertMatchesRegularExpression('/[!@#$%]/', $password);
    }
}