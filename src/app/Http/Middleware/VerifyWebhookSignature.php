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
        // Obtener secret desde config (con fallback a env directo)
        $secret = config('services.medusa.webhook_secret') 
                  ?? env('MEDUSA_WEBHOOK_SECRET');

        // Si no hay secret configurado, registrar advertencia pero permitir en desarrollo
        if (empty($secret)) {
            if (app()->environment('local', 'development')) {
                Log::warning('⚠️ MEDUSA_WEBHOOK_SECRET no configurado - Webhook signature verification DISABLED');
                return $next($request);
            }
            
            Log::error('❌ MEDUSA_WEBHOOK_SECRET no configurado en producción');
            return response()->json([
                'error' => 'Webhook signature verification not configured'
            ], 500);
        }

        // Obtener signature del header
        $signature = $request->header('X-Medusa-Signature');
        
        if (empty($signature)) {
            Log::warning('⚠️ Webhook sin signature header', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);
            
            return response()->json([
                'error' => 'Missing webhook signature'
            ], 401);
        }

        // Obtener payload raw
        $payload = $request->getContent();

        // Calcular signature esperado
        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        // Comparación segura
        if (!hash_equals($expectedSignature, $signature)) {
            // Log solo en desarrollo
            if (app()->environment('local', 'development')) {
                Log::warning('🔐 Webhook signature mismatch', [
                    'signature_received' => substr($signature, 0, 20) . '...',
                    'signature_expected' => substr($expectedSignature, 0, 20) . '...',
                    'payload_length' => strlen($payload),
                    'payload_preview' => substr($payload, 0, 100),
                ]);
            } else {
                // En producción, log mínimo
                Log::warning('🔐 Webhook signature verification failed');
            }

            return response()->json([
                'error' => 'Invalid webhook signature'
            ], 401);
        }

        // Signature válido
        if (app()->environment('local', 'development')) {
            Log::info('✅ Webhook signature verified successfully');
        }

        return $next($request);
    }
}