#!/bin/bash

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}  🔧 Diagnóstico y Reparación Rápida${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}\n"

# 1. Verificar sintaxis del controlador
echo -e "${YELLOW}1️⃣  Verificando sintaxis del controlador...${NC}"
if docker exec laravel-app php -l app/Http/Controllers/Api/MedusaWebhookController.php 2>&1 | grep -q "No syntax errors"; then
    echo -e "${GREEN}✅ Sintaxis correcta${NC}\n"
else
    echo -e "${RED}❌ ERROR DE SINTAXIS:${NC}"
    docker exec laravel-app php -l app/Http/Controllers/Api/MedusaWebhookController.php
    echo -e "\n${RED}Corrige el error de sintaxis y vuelve a ejecutar${NC}"
    exit 1
fi

# 2. Verificar que todas las clases existan
echo -e "${YELLOW}2️⃣  Verificando clases necesarias...${NC}"
CLASSES=(
    "App\DTOs\MedusaOrderDTO"
    "App\DTOs\MoodleUserDTO"
    "App\Jobs\CreateMoodleUserJob"
    "App\Events\MoodleUserCreated"
    "App\Contracts\MoodleServiceInterface"
)

ALL_OK=true
for class in "${CLASSES[@]}"; do
    if docker exec laravel-app php artisan tinker --execute="class_exists('$class') ? print('OK') : print('FAIL');" 2>/dev/null | grep -q "OK"; then
        echo -e "  ${GREEN}✅${NC} $class"
    else
        echo -e "  ${RED}❌${NC} $class ${RED}NO ENCONTRADA${NC}"
        ALL_OK=false
    fi
done

if [ "$ALL_OK" = false ]; then
    echo -e "\n${RED}❌ Faltan clases. Ejecuta: composer dump-autoload${NC}"
    echo -e "${YELLOW}Intentando regenerar autoload...${NC}"
    
    # Buscar composer en diferentes ubicaciones
    if docker exec laravel-app test -f /usr/local/bin/composer; then
        docker exec laravel-app /usr/local/bin/composer dump-autoload
    elif docker exec laravel-app test -f /usr/bin/composer; then
        docker exec laravel-app /usr/bin/composer dump-autoload
    else
        echo -e "${YELLOW}⚠️  Composer no encontrado en el contenedor${NC}"
        echo -e "${YELLOW}Copiando manualmente...${NC}"
        # Alternativa: regenerar autoload de otra forma
        docker exec laravel-app php artisan optimize:clear
    fi
fi
echo ""

# 3. Limpiar TODOS los caches
echo -e "${YELLOW}3️⃣  Limpiando caches...${NC}"
docker exec laravel-app php artisan config:clear > /dev/null 2>&1
docker exec laravel-app php artisan route:clear > /dev/null 2>&1
docker exec laravel-app php artisan cache:clear > /dev/null 2>&1
docker exec laravel-app php artisan view:clear > /dev/null 2>&1
docker exec laravel-app php artisan event:clear > /dev/null 2>&1
echo -e "${GREEN}✅ Caches limpiados${NC}\n"

# 4. Verificar rutas
echo -e "${YELLOW}4️⃣  Verificando rutas...${NC}"
if docker exec laravel-app php artisan route:list --path=webhook 2>&1 | grep -q "order-paid"; then
    echo -e "${GREEN}✅ Ruta registrada${NC}\n"
else
    echo -e "${RED}❌ Ruta NO encontrada${NC}\n"
    echo -e "${YELLOW}Mostrando todas las rutas API:${NC}"
    docker exec laravel-app php artisan route:list --path=api
    echo ""
fi

# 5. Reiniciar contenedores
echo -e "${YELLOW}5️⃣  Reiniciando contenedores...${NC}"
docker-compose restart app > /dev/null 2>&1
sleep 3
docker-compose restart web > /dev/null 2>&1
sleep 2
echo -e "${GREEN}✅ Contenedores reiniciados${NC}\n"

# 6. Verificar que estén corriendo
echo -e "${YELLOW}6️⃣  Verificando estado de contenedores...${NC}"
if docker ps | grep -q "laravel-app"; then
    echo -e "${GREEN}✅ laravel-app corriendo${NC}"
else
    echo -e "${RED}❌ laravel-app NO está corriendo${NC}"
    echo -e "${RED}Ver logs: docker-compose logs app${NC}"
    exit 1
fi

if docker ps | grep -q "laravel-nginx"; then
    echo -e "${GREEN}✅ laravel-nginx corriendo${NC}"
else
    echo -e "${RED}❌ laravel-nginx NO está corriendo${NC}"
fi
echo ""

# 7. Test de conectividad PHP-FPM
echo -e "${YELLOW}7️⃣  Probando PHP-FPM...${NC}"
if docker exec laravel-app php artisan --version > /dev/null 2>&1; then
    echo -e "${GREEN}✅ PHP-FPM responde${NC}\n"
else
    echo -e "${RED}❌ PHP-FPM no responde${NC}\n"
    exit 1
fi

# 8. Probar el endpoint directamente
echo -e "${YELLOW}8️⃣  Probando endpoint...${NC}"
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/api/webhooks/health)
if [ "$HTTP_CODE" = "200" ] || [ "$HTTP_CODE" = "404" ]; then
    echo -e "${GREEN}✅ Nginx responde (HTTP $HTTP_CODE)${NC}\n"
else
    echo -e "${RED}❌ Nginx no responde correctamente (HTTP $HTTP_CODE)${NC}\n"
fi

# Resumen
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${GREEN}✅ Diagnóstico completado${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}\n"

echo -e "${YELLOW}🔍 Próximo paso:${NC}"
echo "   ./test-webhook-improved.sh"
echo ""
echo -e "${YELLOW}Si sigue fallando, ejecuta:${NC}"
echo "   docker-compose logs -f app"
echo "   docker exec -it laravel-app bash"