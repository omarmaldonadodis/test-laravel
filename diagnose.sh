#!/bin/bash

# ============================================
# Script de Diagnóstico
# ============================================

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}  Diagnóstico del Sistema${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}\n"

# 1. Ver logs de Laravel
echo -e "${YELLOW}📋 1. Últimos logs de Laravel:${NC}"
docker exec laravel-app tail -n 50 storage/logs/laravel.log
echo -e "\n${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}\n"

# 2. Verificar ruta del webhook
echo -e "${YELLOW}🔍 2. Verificando rutas:${NC}"
docker exec laravel-app php artisan route:list --path=webhook
echo -e "\n${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}\n"

# 3. Verificar DTOs existen
echo -e "${YELLOW}📁 3. Verificando archivos necesarios:${NC}"
FILES=(
    "app/DTOs/MedusaOrderDTO.php"
    "app/DTOs/MoodleUserDTO.php"
    "app/Contracts/MoodleServiceInterface.php"
    "app/Jobs/CreateMoodleUserJob.php"
    "app/Events/MoodleUserCreated.php"
    "app/Listeners/EnrollUserInCourseListener.php"
    "app/Exceptions/MoodleServiceException.php"
)

for file in "${FILES[@]}"; do
    if docker exec laravel-app test -f "$file"; then
        echo -e "  ${GREEN}✅${NC} $file"
    else
        echo -e "  ${RED}❌${NC} $file ${RED}(NO EXISTE)${NC}"
    fi
done
echo -e "\n${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}\n"

# 4. Verificar Service Provider
echo -e "${YELLOW}🔧 4. Verificando Service Providers:${NC}"
docker exec laravel-app grep -n "MoodleServiceInterface" app/Providers/AppServiceProvider.php 2>/dev/null && echo -e "  ${GREEN}✅ AppServiceProvider configurado${NC}" || echo -e "  ${RED}❌ AppServiceProvider NO configurado${NC}"
echo -e "\n${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}\n"

# 5. Verificar autoload
echo -e "${YELLOW}🔄 5. Regenerando autoload:${NC}"
docker exec laravel-app composer dump-autoload
echo -e "\n${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}\n"

# 6. Verificar config
echo -e "${YELLOW}⚙️  6. Limpiando cache:${NC}"
docker exec laravel-app php artisan config:clear
docker exec laravel-app php artisan route:clear
docker exec laravel-app php artisan cache:clear
echo -e "  ${GREEN}✅ Cache limpiado${NC}"
echo -e "\n${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}\n"

# 7. Probar el controlador existe
echo -e "${YELLOW}🎮 7. Verificando Controller:${NC}"
docker exec laravel-app php artisan tinker --execute="
try {
    \$controller = new App\Http\Controllers\Api\MedusaWebhookController();
    echo 'Controller existe' . PHP_EOL;
} catch (\Exception \$e) {
    echo 'ERROR: ' . \$e->getMessage() . PHP_EOL;
}
"
echo -e "\n${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}\n"

# 8. Ver últimos logs de NGINX
echo -e "${YELLOW}🌐 8. Logs de NGINX (últimas 20 líneas):${NC}"
docker-compose logs --tail=20 web
echo -e "\n${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}\n"

echo -e "${GREEN}✅ Diagnóstico completado${NC}"
echo -e "\n${YELLOW}💡 Próximo paso: Revisa los errores arriba y copia el resultado${NC}"