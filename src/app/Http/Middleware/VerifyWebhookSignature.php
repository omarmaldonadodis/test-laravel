<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.medusa.medusa_webhook_secret');
        
        if (!$secret) {
            \Log::error('🔴 Webhook secret not configured in Laravel');
            return response()->json(['error' => 'Server configuration error'], 500);
        }

        $signature = $request->header('x-medusa-signature');
        
        if (!$signature) {
            \Log::warning('⚠️ Webhook without signature', ['ip' => $request->ip()]);
            return response()->json(['error' => 'Missing signature'], 401);
        }

        $payload = $request->getContent();
        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        // 🐛 DEBUG: Logs detallados
        \Log::info('🔍 DEBUG WEBHOOK VALIDATION:', [
            'secret_length' => strlen($secret),
            'secret_preview' => substr($secret, 0, 10) . '...',
            'received_signature' => $signature,
            'expected_signature' => $expectedSignature,
            'signatures_match' => hash_equals($expectedSignature, $signature),
            'payload_length' => strlen($payload),
            'payload_preview' => substr($payload, 0, 150),
            'ip' => $request->ip(),
        ]);

        if (!hash_equals($expectedSignature, $signature)) {
            \Log::warning('❌ Invalid webhook signature', [
                'ip' => $request->ip(),
                'received' => substr($signature, 0, 20) . '...',
                'expected' => substr($expectedSignature, 0, 20) . '...',
            ]);
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        \Log::info('✅ Webhook signature validated successfully');
        return $next($request);
    }
}