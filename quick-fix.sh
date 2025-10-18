#!/bin/bash

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}  🔧 Reparación de Webhook System${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}\n"

# 1. Verificar/Agregar Secret si no existe
echo -e "${YELLOW}1️⃣  Configurando MEDUSA_WEBHOOK_SECRET...${NC}"
if ! docker exec laravel-app grep -q "MEDUSA_WEBHOOK_SECRET" .env; then
    echo -e "  ${YELLOW}⚠️ Secret no encontrado en .env, agregando...${NC}"
    docker exec laravel-app sh -c "echo 'MEDUSA_WEBHOOK_SECRET=your-secret-key-here' >> .env"
    echo -e "  ${GREEN}✅ Secret agregado a .env${NC}"
    echo -e "  ${RED}⚠️ IMPORTANTE: Cambia 'your-secret-key-here' por tu secret real${NC}"
else
    echo -e "  ${GREEN}✅ Secret ya existe en .env${NC}"
fi

# 2. Ejecutar migración de processed_webhooks
echo -e "\n${YELLOW}2️⃣  Actualizando estructura de BD...${NC}"
docker exec laravel-app php artisan migrate --force
echo -e "  ${GREEN}✅ Migraciones ejecutadas${NC}"

# 3. Limpiar caches
echo -e "\n${YELLOW}3️⃣  Limpiando caches...${NC}"
docker exec laravel-app php artisan config:clear
docker exec laravel-app php artisan cache:clear
docker exec laravel-app php artisan route:clear
echo -e "  ${GREEN}✅ Caches limpiados${NC}"

# 4. Verificar autoload
echo -e "\n${YELLOW}4️⃣  Regenerando autoload...${NC}"
docker exec laravel-app composer dump-autoload -o 2>/dev/null
echo -e "  ${GREEN}✅ Autoload regenerado${NC}"

# 5. Reiniciar servicios
echo -e "\n${YELLOW}5️⃣  Reiniciando servicios...${NC}"
docker-compose restart app queue-worker
sleep 3
echo -e "  ${GREEN}✅ Servicios reiniciados${NC}"

# 6. Verificar estado
echo -e "\n${YELLOW}6️⃣  Verificando estado...${NC}"
docker exec laravel-app php artisan tinker --execute="
echo 'Secret configurado: ' . (config('services.medusa.webhook_secret') ? '✅' : '❌') . PHP_EOL;
echo 'ProcessedWebhook model: ' . (class_exists('App\Models\ProcessedWebhook') ? '✅' : '❌') . PHP_EOL;
echo 'Idempotency Service: ' . (class_exists('App\Services\Webhook\WebhookIdempotencyService') ? '✅' : '❌') . PHP_EOL;
"

echo -e "\n${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${GREEN}✅ Reparación Completada${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}\n"

echo -e "${YELLOW}📋 Próximos pasos:${NC}"
echo "   1. Verificar que MEDUSA_WEBHOOK_SECRET en .env tenga el valor correcto"
echo "   2. Ejecutar: ./debug_webhook_complete.sh"
echo "   3. Probar webhook desde Medusa"