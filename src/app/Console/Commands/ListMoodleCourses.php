<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ListMoodleCourses extends Command
{
    protected $signature = 'moodle:list-courses';
    protected $description = 'List all available Moodle courses';

    public function handle()
    {
        $url = env('MOODLE_URL') . '/webservice/rest/server.php';
        $token = env('MOODLE_TOKEN');

        $this->info('📚 LISTING ALL MOODLE COURSES');

        try {
            $response = Http::timeout(10)
                ->asForm()
                ->post($url, [
                    'wstoken' => $token,
                    'wsfunction' => 'core_course_get_courses',
                    'moodlewsrestformat' => 'json'
                ]);

            $this->line("Status: " . $response->status());

            if ($response->successful()) {
                $courses = $response->json();
                
                if (is_array($courses) && count($courses) > 0) {
                    $this->info("\n🎓 AVAILABLE COURSES (" . count($courses) . " total):");
                    
                    foreach ($courses as $course) {
                        $this->line("• ID: {$course['id']} - {$course['fullname']} ({$course['shortname']})");
                        $this->line("  Visible: " . ($course['visible'] ? 'Yes' : 'No'));
                        $this->line("  Start: " . date('Y-m-d', $course['startdate'] ?? 0));
                        $this->line("");
                    }
                    
                    // Sugerir el primer curso visible como default
                    $visibleCourses = array_filter($courses, fn($c) => $c['visible'] == 1);
                    if (count($visibleCourses) > 0) {
                        $firstCourse = reset($visibleCourses);
                        $this->info("💡 SUGGESTION: Use course ID {$firstCourse['id']} as default");
                    }
                } else {
                    $this->error("❌ No courses found or empty response");
                    $this->line("Response: " . $response->body());
                }
            } else {
                $this->error("❌ HTTP Error: " . $response->status());
                $this->line("Response: " . $response->body());
            }

        } catch (\Exception $e) {
            $this->error("❌ Exception: " . $e->getMessage());
        }
    }
}