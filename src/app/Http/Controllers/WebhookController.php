<?php

namespace App\Http\Controllers;

use App\Models\EnrollmentLog;
use App\Services\MoodleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WebhookController extends Controller
{
    private MoodleService $moodleService;

    public function __construct(MoodleService $moodleService)
    {
        $this->moodleService = $moodleService;
    }


    private function handlePaymentSuccess(array $payload): \Illuminate\Http\JsonResponse
    {
        $paymentIntent = $payload['data']['object'] ?? null;

        if (!$paymentIntent) {
            Log::error('PaymentIntent no encontrado en el payload');
            return response()->json(['error' => 'Invalid payload'], 400);
        }

        $paymentIntentId = $paymentIntent['id'];
        $metadata = $paymentIntent['metadata'] ?? [];
        $customerEmail = $paymentIntent['receipt_email'] 
            ?? $metadata['customer_email'] 
            ?? $metadata['email']
            ?? null;

        $customerName = $metadata['customer_name'] 
            ?? $metadata['name'] 
            ?? 'Usuario';
        
        $medusaOrderId = $metadata['order_id'] 
            ?? $metadata['medusa_order_id'] 
            ?? null;
        
        $courseId = $metadata['course_id'] ?? config('services.moodle.default_course_id');

        if (!$customerEmail) {
            Log::error('Email del cliente no encontrado', ['payment_intent' => $paymentIntentId]);
            return response()->json(['error' => 'Customer email required'], 400);
        }

        // Verificar si ya fue procesado
        $existingLog = EnrollmentLog::where('stripe_payment_intent_id', $paymentIntentId)->first();
        if ($existingLog && $existingLog->status === 'success') {
            Log::info('Pago ya procesado', ['payment_intent' => $paymentIntentId]);
            return response()->json(['message' => 'Already processed'], 200);
        }

        // Crear o actualizar log
        $enrollmentLog = EnrollmentLog::updateOrCreate(
            ['stripe_payment_intent_id' => $paymentIntentId],
            [
                'medusa_order_id' => $medusaOrderId,
                'customer_email' => $customerEmail,
                'customer_name' => $customerName,
                'moodle_course_id' => $courseId,
                'status' => 'pending',
                'webhook_payload' => $payload,
            ]
        );

        try {
            // Buscar o crear usuario en Moodle
            $moodleUser = $this->moodleService->getUserByEmail($customerEmail);
            
            if (!$moodleUser) {
                // Crear nuevo usuario
                $nameParts = $this->parseFullName($customerName);
                $username = $this->generateUsername($customerEmail);
                $password = Str::random(16);

                $userData = [
                    'username' => $username,
                    'password' => $password,
                    'firstname' => $nameParts['firstname'],
                    'lastname' => $nameParts['lastname'],
                    'email' => $customerEmail,
                ];

                $moodleUserId = $this->moodleService->createUser($userData);

                if (!$moodleUserId) {
                    throw new \Exception('No se pudo crear el usuario en Moodle');
                }

                Log::info('Nuevo usuario creado en Moodle', [
                    'email' => $customerEmail,
                    'user_id' => $moodleUserId
                ]);

                // TODO: Enviar email con credenciales al usuario
                // Mail::to($customerEmail)->send(new WelcomeToMoodle($username, $password));

            } else {
                $moodleUserId = $moodleUser['id'];
                Log::info('Usuario existente encontrado', ['user_id' => $moodleUserId]);
            }

            // Matricular en el curso
            $enrolled = $this->moodleService->enrollUserInCourse($moodleUserId, $courseId);

            if (!$enrolled) {
                throw new \Exception('No se pudo matricular al usuario en el curso');
            }

            // Actualizar log como exitoso
            $enrollmentLog->update([
                'moodle_user_id' => $moodleUserId,
                'status' => 'success',
            ]);

            Log::info('Proceso completado exitosamente', [
                'payment_intent' => $paymentIntentId,
                'moodle_user_id' => $moodleUserId,
                'course_id' => $courseId
            ]);

            return response()->json([
                'message' => 'Usuario creado y matriculado exitosamente',
                'moodle_user_id' => $moodleUserId,
                'course_id' => $courseId
            ], 200);

        } catch (\Exception $e) {
            $enrollmentLog->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            Log::error('Error en el proceso de matriculación', [
                'payment_intent' => $paymentIntentId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Error al procesar la matriculación',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private function parseFullName(string $fullName): array
    {
        $parts = explode(' ', trim($fullName), 2);
        
        return [
            'firstname' => $parts[0] ?? 'Usuario',
            'lastname' => $parts[1] ?? 'Apellido',
        ];
    }

    private function generateUsername(string $email): string
    {
        $username = explode('@', $email)[0];
        $username = preg_replace('/[^a-zA-Z0-9]/', '', $username);
        return strtolower($username) . rand(100, 999);
    }
}