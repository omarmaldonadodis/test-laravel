<?php
// tests/Unit/Middleware/VerifyWebhookSignatureSecurityTest.php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\VerifyWebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class VerifyWebhookSignatureSecurityTest extends TestCase
{
    private VerifyWebhookSignature $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new VerifyWebhookSignature();
        config(['services.medusa.medusa_webhook_secret' => 'test-secret-key']);
    }

    
    public function test_it_does_not_log_secret_in_production()
    {
        // Arrange
        app()->detectEnvironment(fn() => 'production');
        
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message, $context = []) {
                // Verificar que NO contiene el secret
                $hasSecret = str_contains($message, 'test-secret-key') ||
                             (is_array($context) && str_contains(json_encode($context), 'test-secret-key'));
                
                return $message === 'Webhook signature verification failed' && !$hasSecret;
            });

        $request = Request::create('/webhook', 'POST', [], [], [], [], '{"test":"data"}');
        $request->headers->set('X-Medusa-Signature', 'invalid-signature');

        // Act
        $response = $this->middleware->handle($request, fn() => response('OK'));

        // Assert
        $this->assertEquals(401, $response->getStatusCode());
    }

   
    public function test_it_does_not_log_payload_in_production()
    {
        // Arrange
        app()->detectEnvironment(fn() => 'production');
        
        $sensitivePayload = json_encode([
            'customer_email' => 'secret@example.com',
            'credit_card' => '1234-5678-9012-3456'
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message, $context = []) use ($sensitivePayload) {
                // Verificar que NO contiene el payload
                return !str_contains(json_encode($context), 'secret@example.com');
            });

        $request = Request::create('/webhook', 'POST', [], [], [], [], $sensitivePayload);
        $request->headers->set('X-Medusa-Signature', 'invalid');

        // Act
        $this->middleware->handle($request, fn() => response('OK'));
    }


    public function test_it_logs_detailed_info_only_in_development()
    {
        // Arrange
        app()->detectEnvironment(fn() => 'local');
        
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'Webhook signature mismatch'
                    && isset($context['signature_present'])
                    && isset($context['payload_length'])
                    && !isset($context['secret']); // Pero no el secret mismo
            });

        $request = Request::create('/webhook', 'POST', [], [], [], [], '{"test":"data"}');
        $request->headers->set('X-Medusa-Signature', 'invalid');

        // Act
        $this->middleware->handle($request, fn() => response('OK'));
    }

   
    public function test_it_verifies_valid_signature_without_excessive_logging()
    {
        // Arrange
        app()->detectEnvironment(fn() => 'production');
        
        $payload = '{"test":"data"}';
        $validSignature = hash_hmac('sha256', $payload, 'test-secret-key');

        // En producción NO debe loguear nada cuando es exitoso
        Log::shouldReceive('info')->never();
        Log::shouldReceive('debug')->never();

        $request = Request::create('/webhook', 'POST', [], [], [], [], $payload);
        $request->headers->set('X-Medusa-Signature', $validSignature);

        // Act
        $response = $this->middleware->handle($request, fn() => response('OK'));

        // Assert
        $this->assertEquals(200, $response->getStatusCode());
    }
}