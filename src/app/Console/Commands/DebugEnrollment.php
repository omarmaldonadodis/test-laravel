<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DebugEnrollment extends Command
{
    protected $signature = 'debug:enrollment 
                            {user_id : Moodle user ID}
                            {course_id=2 : Course ID}';
    
    protected $description = 'Debug course enrollment';

    public function handle()
    {
        $userId = $this->argument('user_id');
        $courseId = $this->argument('course_id');

        $this->info('🎓 DEBUGGING COURSE ENROLLMENT');
        $this->line("User ID: {$userId}");
        $this->line("Course ID: {$courseId}");

        $url = env('MOODLE_URL') . '/webservice/rest/server.php';
        $token = env('MOODLE_TOKEN');

        // Paso 1: Verificar si el usuario existe
        $this->info("\n1. 🔍 VERIFYING USER EXISTS...");
        
        try {
            $response = Http::timeout(10)
                ->asForm()
                ->post($url, [
                    'wstoken' => $token,
                    'wsfunction' => 'core_user_get_users_by_field',
                    'moodlewsrestformat' => 'json',
                    'field' => 'id',
                    'values[0]' => $userId
                ]);

            $this->line("Status: " . $response->status());
            $data = $response->json();
            
            if (is_array($data) && count($data) > 0) {
                $this->info("✅ User exists: " . $data[0]['username']);
            } else {
                $this->error("❌ User not found");
                return;
            }
        } catch (\Exception $e) {
            $this->error("❌ Error checking user: " . $e->getMessage());
            return;
        }

        // Paso 2: Verificar si el curso existe
        $this->info("\n2. 📚 VERIFYING COURSE EXISTS...");
        
        try {
            $response = Http::timeout(10)
                ->asForm()
                ->post($url, [
                    'wstoken' => $token,
                    'wsfunction' => 'core_course_get_courses',
                    'moodlewsrestformat' => 'json',
                    'options[ids][0]' => $courseId
                ]);

            $this->line("Status: " . $response->status());
            $data = $response->json();
            
            if (is_array($data) && count($data) > 0) {
                $this->info("✅ Course exists: " . $data[0]['fullname']);
            } else {
                $this->error("❌ Course not found");
                $this->line("Available courses might have different IDs");
                return;
            }
        } catch (\Exception $e) {
            $this->error("❌ Error checking course: " . $e->getMessage());
            return;
        }

        // Paso 3: Intentar inscripción
        $this->info("\n3. 🎓 ATTEMPTING ENROLLMENT...");
        
        try {
            $response = Http::timeout(10)
                ->asForm()
                ->post($url, [
                    'wstoken' => $token,
                    'wsfunction' => 'enrol_manual_enrol_users',
                    'moodlewsrestformat' => 'json',
                    'enrolments[0][roleid]' => 5, // Student role
                    'enrolments[0][userid]' => $userId,
                    'enrolments[0][courseid]' => $courseId
                ]);

            $this->line("Status: " . $response->status());
            $this->line("Response: " . $response->body());

            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['exception'])) {
                    $this->error("❌ Enrollment error: " . $data['message']);
                    if (isset($data['debuginfo'])) {
                        $this->line("Debug: " . $data['debuginfo']);
                    }
                } else {
                    $this->info("✅ Enrollment successful!");
                }
            } else {
                $this->error("❌ HTTP error: " . $response->status());
            }
        } catch (\Exception $e) {
            $this->error("❌ Enrollment exception: " . $e->getMessage());
        }

        // Paso 4: Verificar inscripción
        $this->info("\n4. 🔍 VERIFYING ENROLLMENT...");
        
        try {
            $response = Http::timeout(10)
                ->asForm()
                ->post($url, [
                    'wstoken' => $token,
                    'wsfunction' => 'core_enrol_get_users_courses',
                    'moodlewsrestformat' => 'json',
                    'userid' => $userId
                ]);

            $this->line("Status: " . $response->status());
            $data = $response->json();
            
            if (is_array($data)) {
                $enrolledCourses = array_column($data, 'id');
                if (in_array($courseId, $enrolledCourses)) {
                    $this->info("✅ User is enrolled in course {$courseId}");
                } else {
                    $this->warn("⚠️ User is NOT enrolled in course {$courseId}");
                    $this->line("Enrolled in: " . implode(', ', $enrolledCourses));
                }
            }
        } catch (\Exception $e) {
            $this->error("❌ Error verifying enrollment: " . $e->getMessage());
        }
    }
}