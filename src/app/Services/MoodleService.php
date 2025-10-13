<?php

namespace App\Services;

use App\Contracts\MoodleServiceInterface;
use App\Exceptions\MoodleServiceException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\ConnectionException;

/**
 * Servicio de integración con Moodle
 * Implementa: MoodleServiceInterface (Dependency Inversion Principle)
 */
class MoodleService implements MoodleServiceInterface
{
    private string $moodleUrl;
    private string $token;
    private int $timeout;

    public function __construct(private CacheRepository $cache)
    {
        $this->moodleUrl = rtrim(config('services.moodle.url'), '/');
        $this->token = config('services.moodle.token');
        $this->timeout = 30;

        if (empty($this->token)) {
            Log::error('MOODLE_TOKEN no está configurado en el archivo .env');
        }

        Log::info('🔧 MoodleService inicializado', [
            'url' => $this->moodleUrl,
            'token_defined' => !empty($this->token),
            'timeout' => $this->timeout,
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
        return Cache::remember("moodle_user_{$email}", 3600, function () use ($email) {
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
    public function enrollUser(int $userId, int $courseId, int $roleId = 5): bool
    {
        try {
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
        return $username . time();
    }

    /** Genera contraseña segura */
    public function generatePassword(int $length = 12): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%';
        return substr(str_shuffle(str_repeat($chars, ceil($length / strlen($chars)))), 0, $length);
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
            return null;
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

    /** Llamada genérica al API Moodle */
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

            Log::info('📤 Moodle API llamada', [
                'function' => $function,
                'status' => $response->status(),
                'time_ms' => $duration,
            ]);

            if ($response->failed()) {
                throw MoodleServiceException::connectionFailed(
                    "HTTP Error {$response->status()}",
                    ['body' => $response->body()]
                );
            }

            $data = $response->json();
            if (!$data) {
                throw new \Exception('Invalid JSON response from Moodle');
            }

            if (isset($data['exception'])) {
                throw new \Exception("Moodle Error [{$data['errorcode']}]: {$data['message']}");
            }

            return $data;
        } catch (ConnectionException $e) {
            throw MoodleServiceException::connectionFailed($e->getMessage());
        } catch (\Exception $e) {
            Log::error('💥 Error general en callWebService', [
                'function' => $function,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}