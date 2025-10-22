<?php

namespace App\Services;

use App\Contracts\MoodleServiceInterface;
use App\Exceptions\MoodleServiceException;
use App\Services\RateLimiting\MoodleRateLimiter;
use App\Constants\MoodleRoles;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Exception;


class MoodleService implements MoodleServiceInterface
{
    private string $moodleUrl;
    private string $token;
    private int $timeout;
    private MoodleRateLimiter $rateLimiter;

    public function __construct(
        private CacheRepository $cache,
        MoodleRateLimiter $rateLimiter
    ) {
        $this->moodleUrl = rtrim(config('services.moodle.url'), '/');
        $this->token = config('services.moodle.token');
        $this->timeout = config('services.moodle.timeout', 30); 
        $this->rateLimiter = $rateLimiter;

        if (empty($this->token)) {
            Log::error('MOODLE_TOKEN no está configurado en el archivo .env');
        }

        Log::info('🔧 MoodleService inicializado', [
            'url' => $this->moodleUrl,
            'token_defined' => !empty($this->token),
            'timeout' => $this->timeout,
            'rate_limit_enabled' => config('services.moodle.rate_limit.enabled'),
        ]);
    }

    /** Crea un usuario en Moodle */
    public function createUser(array $userData): ?array
    {
        Log::info('👤 Creación de usuario en Moodle iniciada', [
            'email' => $userData['email'],
        ]);

        try {
            $existingUser = $this->getUserByEmail($userData['email']);
            if ($existingUser) {
                Log::info('✅ Usuario ya existe en Moodle', [
                    'email' => $userData['email'],
                    'id' => $existingUser['id'],
                ]);
                return [
                    'id' => $existingUser['id'],
                    'username' => $existingUser['username'],
                    'email' => $existingUser['email'],
                    'firstname' => $existingUser['firstname'] ?? '',
                    'lastname' => $existingUser['lastname'] ?? '',
                    'existing' => true,
                ];
            }

            $users = [[
                'username' => $userData['username'],
                'password' => $userData['password'],
                'firstname' => $userData['firstname'],
                'lastname' => $userData['lastname'],
                'email' => $userData['email'],
                'auth' => 'manual',
                'lang' => 'es',
                'timezone' => 'America/Guayaquil',
                'mailformat' => 1,
                'maildisplay' => 2,
                'city' => 'Loja',
                'country' => 'EC',
            ]];

            $response = $this->callWebService('core_user_create_users', ['users' => $users]);

            if (is_array($response) && isset($response[0]['id'])) {
                Log::info('✅ Usuario creado en Moodle', [
                    'id' => $response[0]['id'],
                    'email' => $userData['email'],
                ]);
                return [
                    'id' => $response[0]['id'],
                    'username' => $userData['username'],
                    'email' => $userData['email'],
                    'firstname' => $userData['firstname'],
                    'lastname' => $userData['lastname'],
                    'password' => $userData['password'],
                    'existing' => false,
                ];
            }

            throw MoodleServiceException::userCreationFailed(
                $userData['email'],
                'Unexpected response format',
                ['response' => $response]
            );
        } catch (MoodleServiceException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('💥 Error al crear usuario en Moodle', [
                'error' => $e->getMessage(),
                'user' => $userData,
            ]);
            throw MoodleServiceException::userCreationFailed(
                $userData['email'],
                $e->getMessage()
            );
        }
    }

    /** Busca usuario por email */
    public function getUserByEmail(string $email): ?array
    {
        return Cache::remember(
            "moodle_user_{$email}", 
            config('services.moodle.cache_ttl', 3600), // ✅ Configurable
            function () use ($email) {           
                try {
                    $response = $this->callWebService('core_user_get_users_by_field', [
                        'field' => 'email',
                        'values' => [$email],
                    ]);

                    if (is_array($response) && count($response) > 0 && isset($response[0]['id'])) {
                        return $response[0];
                    }
                    return null;
                } catch (\Exception $e) {
                    Log::error('❌ Error al obtener usuario por email', [
                        'error' => $e->getMessage(),
                        'email' => $email,
                    ]);
                    return null;
                }
        });
    }

    /** Inscribe usuario en curso */
    public function enrollUser(int $userId, int $courseId, int $roleId = MoodleRoles::STUDENT): bool
    {
        try {
            if (!MoodleRoles::isValid($roleId)) {
                throw new \InvalidArgumentException(
                    "Invalid role ID: {$roleId}. Must be a valid Moodle role."
                );
            }

            $enrolments = [[
                'roleid' => $roleId,
                'userid' => $userId,
                'courseid' => $courseId,
            ]];

            $this->callWebService('enrol_manual_enrol_users', ['enrolments' => $enrolments]);

            Cache::forget("moodle_user_enrollments_{$userId}");

            Log::info('✅ Usuario inscrito', [
                'user_id' => $userId,
                'course_id' => $courseId,
            ]);
            return true;
        } catch (\Exception $e) {
            Log::error('❌ Error al inscribir usuario', [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'course_id' => $courseId,
            ]);
            throw MoodleServiceException::enrollmentFailed($userId, $courseId, $e->getMessage());
        }
    }

    /** Verifica si un usuario está inscrito */
    public function isUserEnrolled(int $userId, int $courseId): bool
    {
        try {
            $response = $this->callWebService('core_enrol_get_users_courses', [
                'userid' => $userId,
            ]);

            return collect($response)->contains(fn($c) => $c['id'] == $courseId);
        } catch (\Exception $e) {
            Log::error('❌ Error al verificar inscripción', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /** Genera username único */
    public function generateUsername(string $email): string
    {
        $username = preg_replace('/[^a-zA-Z0-9]/', '', explode('@', $email)[0]);
        $username = strtolower($username);
        
        if (strlen($username) < 4) {
            $username = substr(md5($email), 0, 8);
        }
        
        // ✅ Usar uniqid con más entropía
        $uniqueSuffix = uniqid('', true); // true = más entropía
        $uniqueSuffix = str_replace('.', '', $uniqueSuffix); // Remover punto
        
        return $username . substr($uniqueSuffix, -10); // Últimos 10 caracteres
    }

    /** Genera contraseña segura */
    public function generatePassword(int $length = 12): string
    {
        // ✅ Asegurar que incluya al menos: 1 letra minúscula, 1 mayúscula, 1 número, 1 especial
        $lowercase = 'abcdefghijklmnopqrstuvwxyz';
        $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $numbers = '0123456789';
        $special = '!@#$%';
        
        // Asegurar al menos un carácter de cada tipo
        $password = '';
        $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
        $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
        $password .= $numbers[random_int(0, strlen($numbers) - 1)];
        $password .= $special[random_int(0, strlen($special) - 1)];
        
        // Rellenar el resto con caracteres aleatorios
        $allChars = $lowercase . $uppercase . $numbers . $special;
        $remaining = $length - 4;
        
        for ($i = 0; $i < $remaining; $i++) {
            $password .= $allChars[random_int(0, strlen($allChars) - 1)];
        }
        
        // Mezclar para que los caracteres obligatorios no estén siempre al inicio
        return str_shuffle($password);
    }

    /** Verifica permisos del token */
    public function checkTokenPermissions(): ?array
    {
        try {
            $info = $this->callWebService('core_webservice_get_site_info');
            Log::info('🔑 Token Moodle válido', [
                'sitename' => $info['sitename'] ?? 'N/A',
                'username' => $info['username'] ?? 'N/A',
            ]);
            return $info;
        } catch (\Exception $e) {
            Log::error('❌ Error verificando token', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /** Prueba conexión */
    public function testConnection(): bool
    {
        try {
            $info = $this->callWebService('core_webservice_get_site_info');
            Log::info('✅ Conexión exitosa con Moodle', [
                'sitename' => $info['sitename'] ?? 'Unknown',
            ]);
            return true;
        } catch (\Exception $e) {
            Log::error('❌ Error al conectar con Moodle', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Llamada genérica al API Moodle con rate limiting
     */
    /**
 * Llamada genérica al API Moodle con rate limiting y sanitización de respuesta
 */
private function callWebService(string $function, array $params = [])
{
    $url = "{$this->moodleUrl}/webservice/rest/server.php";

    $requestParams = array_merge([
        'wstoken' => $this->token,
        'wsfunction' => $function,
        'moodlewsrestformat' => 'json',
    ], $params);

    try {
        $start = microtime(true);
        $response = Http::timeout($this->timeout)->asForm()->post($url, $requestParams);
        $duration = round((microtime(true) - $start) * 1000, 2);

        // Rate limiter
        $this->rateLimiter->hit();

        Log::info('📤 Moodle API llamada', [
            'function' => $function,
            'status' => $response->status(),
            'time_ms' => $duration,
            'rate_limit_remaining' => $this->rateLimiter->remaining(),
        ]);

        // Validar HTTP status
        if ($response->failed()) {
            $status = $response->status();
            $body = $response->body();

            $errorMessage = match($status) {
                401 => 'Unauthorized - Invalid Moodle token',
                403 => 'Forbidden - Insufficient permissions',
                429 => 'Rate limit exceeded',
                500, 502, 503 => 'Moodle server error',
                default => "HTTP Error {$status}",
            };

            throw MoodleServiceException::connectionFailed(
                $errorMessage,
                [
                    'status' => $status,
                    'body' => $body,
                    'function' => $function
                ]
            );
        }

        // Sanitizar body
        $body = trim($response->body(), "\x00..\x1F");


        $data = json_decode($body, true);

        if ($body !== 'null' && $data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON response from Moodle: ' . json_last_error_msg());
        }

        // Validación de errores de Moodle
        if (is_array($data) && isset($data['exception'])) {
            $errorCode = $data['errorcode'] ?? 'unknown';
            $message = $data['message'] ?? 'Unknown Moodle error';

            Log::error('❌ Moodle API error', [
                'function' => $function,
                'error_code' => $errorCode,
                'message' => $message,
            ]);

            throw new \Exception("Moodle Error [{$errorCode}]: {$message}");
        }

        return $data ?? [];

    } catch (ConnectionException $e) {
        Log::error('💥 Connection error to Moodle', [
            'function' => $function,
            'error' => $e->getMessage(),
        ]);
        throw MoodleServiceException::connectionFailed($e->getMessage());

    } catch (MoodleServiceException $e) {
        throw $e;

    } catch (\Exception $e) {
        Log::error('💥 Error general en callWebService', [
            'function' => $function,
            'error' => $e->getMessage(),
            'trace' => config('app.debug') ? $e->getTraceAsString() : null,
        ]);
        throw $e;
    }
}

}