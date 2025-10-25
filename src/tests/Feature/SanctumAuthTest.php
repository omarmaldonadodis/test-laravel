<?php
namespace Tests\Feature;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SanctumAuthTest extends TestCase
{
    use RefreshDatabase;

   
    public function test_it_blocks_unauthenticated_requests_to_protected_routes()
    {
        $response = $this->getJson('/api/enrollment-logs');
        
        $response->assertStatus(401);
    }

    public function test_it_allows_authenticated_requests_with_valid_token()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/enrollment-logs');
        
        $response->assertStatus(200);
    }

    public function test_it_returns_admin_metrics_for_authenticated_users()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/admin/metrics');
        
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'jobs' => ['pending', 'failed'],
            'webhooks' => ['processed', 'last_24h'],
            'users' => ['total', 'with_moodle_id'],
            'enrollments' => ['failed'],
        ]);
    }

    public function test_it_creates_token_via_artisan_command()
    {
        $user = User::factory()->create(['email' => 'test@example.com']);

        $this->artisan('api:create-token', ['email' => 'test@example.com'])
            ->assertSuccessful();

        $this->assertCount(1, $user->tokens);
    }
}
