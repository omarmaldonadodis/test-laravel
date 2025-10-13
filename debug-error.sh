#!/bin/bash

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}  🔍 Debug Detallado del Error${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}\n"

# 1. Limpiar log anterior
echo -e "${YELLOW}1️⃣  Limpiando logs anteriores...${NC}"
docker exec laravel-app sh -c "> storage/logs/laravel.log"
echo -e "${GREEN}✅ Log limpiado${NC}\n"

# 2. Habilitar debug
echo -e "${YELLOW}2️⃣  Verificando APP_DEBUG...${NC}"
DEBUG_STATUS=$(docker exec laravel-app php artisan tinker --execute="echo config('app.debug') ? 'true' : 'false';" 2>/dev/null)
echo "   APP_DEBUG: $DEBUG_STATUS"
if [ "$DEBUG_STATUS" != "true" ]; then
    echo -e "${YELLOW}⚠️  Cambiando APP_DEBUG a true...${NC}"
    docker exec laravel-app sed -i 's/APP_DEBUG=false/APP_DEBUG=true/g' .env
    docker exec laravel-app php artisan config:clear > /dev/null 2>&1
fi
echo ""

# 3. Enviar request de prueba
echo -e "${YELLOW}3️⃣  Enviando request de prueba...${NC}"
TIMESTAMP=$(date +%s)
RESPONSE=$(curl -s -X POST "http://localhost:8080/api/webhooks/medusa/order-paid" \
  -H "Content-Type: application/json" \
  -d "{
    \"id\": \"test_${TIMESTAMP}\",
    \"customer\": {
      \"email\": \"test@example.com\",
      \"first_name\": \"Test\",
      \"last_name\": \"User\"
    },
    \"items\": []
  }")

echo "Respuesta:"
echo "$RESPONSE" | jq . 2>/dev/null || echo "$RESPONSE"
echo ""

# 4. Ver el error completo en el log
echo -e "${YELLOW}4️⃣  Últimos errores en Laravel log:${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
docker exec laravel-app tail -n 50 storage/logs/laravel.log | grep -A 20 "ERROR" || echo "No hay errores recientes"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}\n"

# 5. Probar el controlador directamente
echo -e "${YELLOW}5️⃣  Probando instanciar el controlador...${NC}"
docker exec laravel-app php artisan tinker --execute="
try {
    \$controller = new App\Http\Controllers\Api\MedusaWebhookController();
    echo 'Controller OK\n';
} catch (\Exception \$e) {
    echo 'ERROR: ' . \$e->getMessage() . '\n';
    echo 'File: ' . \$e->getFile() . ':' . \$e->getLine() . '\n';
}
"
echo ""

# 6. Verificar todas las dependencias del Job
echo -e "${YELLOW}6️⃣  Verificando dependencias del Job...${NC}"
docker exec laravel-app php artisan tinker --execute="
try {
    echo 'Verificando CreateMoodleUserJob...\n';
    \$reflection = new ReflectionClass('App\Jobs\CreateMoodleUserJob');
    echo 'Constructor params: ' . count(\$reflection->getConstructor()->getParameters()) . '\n';
    
    echo '\nVerificando MoodleServiceInterface...\n';
    \$hasBinding = app()->bound('App\Contracts\MoodleServiceInterface');
    echo 'Interface bound: ' . (\$hasBinding ? 'YES' : 'NO') . '\n';
    
    if (\$hasBinding) {
        \$service = app('App\Contracts\MoodleServiceInterface');
        echo 'Service class: ' . get_class(\$service) . '\n';
    }
} catch (\Exception \$e) {
    echo 'ERROR: ' . \$e->getMessage() . '\n';
}
"
echo ""

# 7. Probar crear un DTO manualmente
echo -e "${YELLOW}7️⃣  Probando crear MedusaOrderDTO...${NC}"
docker exec laravel-app php artisan tinker --execute="
try {
    \$dto = App\DTOs\MedusaOrderDTO::fromWebhookPayload([
        'id' => 'test_123',
        'customer' => [
            'email' => 'test@example.com',
            'first_name' => 'Test',
            'last_name' => 'User'
        ],
        'items' => []
    ]);
    echo 'DTO created successfully\n';
    echo 'Valid: ' . (\$dto->isValid() ? 'YES' : 'NO') . '\n';
} catch (\Exception \$e) {
    echo 'ERROR: ' . \$e->getMessage() . '\n';
}
"
echo ""

# 8. Ver el contenido del controlador
echo -e "${YELLOW}8️⃣  Primeras líneas del controlador:${NC}"
docker exec laravel-app head -20 app/Http/Controllers/Api/MedusaWebhookController.php
echo ""

# 9. Ver provider
echo -e "${YELLOW}9️⃣  AppServiceProvider bindings:${NC}"
docker exec laravel-app grep -A 5 "MoodleServiceInterface" app/Providers/AppServiceProvider.php
echo ""

echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${GREEN}✅ Debug completado${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}\n"

echo -e "${YELLOW}💡 Busca en la salida arriba:${NC}"
echo "   - Mensajes de ERROR"
echo "   - 'Interface bound: NO' (significa que falta el binding)"
echo "   - Excepciones o stack traces"