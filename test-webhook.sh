#!/bin/bash

# ============================================
# Script de Prueba del Webhook
# ============================================

GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}  Probando Webhook de Medusa${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}\n"

# URL del webhook
WEBHOOK_URL="http://localhost:8080/api/webhooks/medusa/order-paid"

# Payload de prueba
PAYLOAD='{
  "id": "order_test_'$(date +%s)'",
  "customer": {
    "email": "testwebhook@example.com",
    "first_name": "Juan",
    "last_name": "Pérez",
    "id": "cust_123"
  },
  "items": [
    {
      "id": "item_1",
      "title": "Curso de Laravel",
      "metadata": {
        "moodle_course_id": 2
      }
    }
  ],
  "metadata": {}
}'

echo -e "${BLUE}📤 Enviando webhook...${NC}\n"

# Enviar request
RESPONSE=$(curl -s -w "\nHTTP_CODE:%{http_code}" -X POST "$WEBHOOK_URL" \
  -H "Content-Type: application/json" \
  -d "$PAYLOAD")

# Extraer código HTTP
HTTP_CODE=$(echo "$RESPONSE" | grep "HTTP_CODE" | cut -d: -f2)
BODY=$(echo "$RESPONSE" | sed '/HTTP_CODE/d')

# Mostrar respuesta
echo -e "${BLUE}📥 Respuesta del servidor:${NC}"
echo "$BODY" | jq . 2>/dev/null || echo "$BODY"
echo ""

# Verificar código de respuesta
if [ "$HTTP_CODE" = "202" ]; then
    echo -e "${GREEN}✅ Webhook aceptado (HTTP 202)${NC}\n"
else
    echo -e "${YELLOW}⚠️  Código HTTP: $HTTP_CODE${NC}\n"
fi

# Esperar un momento
echo -e "${BLUE}⏳ Esperando procesamiento (5 segundos)...${NC}"
sleep 5

# Verificar estado de la cola
echo -e "\n${BLUE}📊 Estado de la cola:${NC}"
JOBS_IN_QUEUE=$(docker exec laravel-redis redis-cli LLEN queues:default 2>/dev/null || echo "?")
echo "   Jobs pendientes: $JOBS_IN_QUEUE"

# Ver últimos logs del worker
echo -e "\n${BLUE}📝 Últimos logs del worker:${NC}"
docker-compose logs --tail=20 queue-worker

echo -e "\n${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${GREEN}Prueba completada${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""
echo "🔍 Para ver más detalles:"
echo "   docker-compose logs -f queue-worker"
echo "   docker exec laravel-app php artisan queue:failed"