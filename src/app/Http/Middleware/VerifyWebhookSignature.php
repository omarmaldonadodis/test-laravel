<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $signature = $request->header('X-Medusa-Signature');
        $payload = $request->getContent();
        $secret = config('services.medusa.medusa_webhook_secret');

        if (empty($signature) || empty($secret)) {
            Log::warning('Webhook signature verification failed: missing signature or secret');
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        if (!hash_equals($expectedSignature, $signature)) {
            // SOLO log en desarrollo, SIN datos sensibles
            if (app()->environment('local', 'development')) {
                Log::warning('Webhook signature mismatch', [
                    'signature_present' => !empty($signature),
                    'payload_length' => strlen($payload),
                    'signature_format_valid' => ctype_xdigit($signature ?? ''),
                ]);
            } else {
                // En producción, log mínimo
                Log::warning('Webhook signature verification failed');
            }

            return response()->json(['error' => 'Invalid signature'], 401);
        }

        // Log exitoso solo en desarrollo
        if (app()->environment('local', 'development')) {
            Log::info('Webhook signature verified successfully');
        }

        return $next($request);
    }
}