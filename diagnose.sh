#!/bin/bash

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}  🧪 Test de Webhook con Payload Real${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}\n"

# 1. Obtener secret
echo -e "${YELLOW}1️⃣  Obteniendo configuración...${NC}"
SECRET=$(docker exec laravel-app php artisan tinker --execute="echo config('services.medusa.medusa_webhook_secret');")
SECRET=$(echo "$SECRET" | tr -d '\n' | tr -d '\r')

echo -e "  ${GREEN}✅${NC} Secret configurado (${#SECRET} chars)"

# 2. Crear payload con estructura correcta de Medusa
TIMESTAMP=$(date +%s)
EMAIL="testsd@example.com"
ORDER_ID="order_${TIMESTAMP}"

# Payload que coincide con la estructura que espera MedusaOrderDTO
PAYLOAD=$(cat <<EOF
{
  "id": "order_test_123456",
  "type": "order.paid",
  "customer": {
    "id": "cus_${TIMESTAMP}",
    "email": "${EMAIL}",
    "first_name": "John",
    "last_name": "Doe"
  },
  "items": [
    {
      "id": "item_001",
      "title": "Curso de Laravel",
      "quantity": 1,
      "metadata": {
        "moodle_course_id": 2
      }
    }
  ],
  "metadata": {
    "source": "test"
  }
}
EOF
)

echo -e "\n${YELLOW}2️⃣  Payload generado:${NC}"
echo "$PAYLOAD" | jq .

# 3. Generar signature
echo -e "\n${YELLOW}3️⃣  Generando signature...${NC}"
SIGNATURE=$(echo -n "$PAYLOAD" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $2}')
echo -e "  ${BLUE}Signature:${NC} $SIGNATURE"

# 4. Verificar con Laravel que la signature es correcta
echo -e "\n${YELLOW}4️⃣  Validando signature...${NC}"
docker exec laravel-app php artisan tinker --execute="
\$payload = '${PAYLOAD}';
\$signature = '${SIGNATURE}';
\$expected = hash_hmac('sha256', \$payload, config('services.medusa.medusa_webhook_secret'));
if (hash_equals(\$expected, \$signature)) {
    echo '✅ Signature válida' . PHP_EOL;
} else {
    echo '❌ Signature inválida' . PHP_EOL;
}
"

# 5. Enviar webhook
echo -e "\n${YELLOW}5️⃣  Enviando webhook...${NC}"
RESPONSE=$(curl -s -w "\n%{http_code}" -X POST "http://localhost:8080/api/webhooks/medusa/order-paid" \
  -H "Content-Type: application/json" \
  -H "X-Medusa-Signature: $SIGNATURE" \
  -d "$PAYLOAD")

HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
BODY=$(echo "$RESPONSE" | sed '$d')

echo -e "  ${BLUE}HTTP Status:${NC} $HTTP_CODE"
echo -e "  ${BLUE}Response:${NC}"
echo "$BODY" | jq . 2>/dev/null || echo "$BODY"

# 6. Verificar resultado
echo -e "\n${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"

if [ "$HTTP_CODE" = "202" ]; then
    echo -e "${GREEN}✅ ÉXITO TOTAL: Webhook procesado correctamente${NC}"
    
    sleep 2
    
    # Verificar en BD
    echo -e "\n${YELLOW}Verificando datos guardados...${NC}"
    docker exec laravel-app php artisan tinker --execute="
    echo '📊 PROCESSED WEBHOOKS:' . PHP_EOL;
    \$webhook = App\Models\ProcessedWebhook::where('medusa_order_id', '${ORDER_ID}')->first();
    if (\$webhook) {
        echo '  ✅ Webhook ID: ' . \$webhook->webhook_id . PHP_EOL;
        echo '  ✅ Order ID: ' . \$webhook->medusa_order_id . PHP_EOL;
        echo '  ✅ Email: ' . \$webhook->user_email . PHP_EOL;
        echo '  ✅ Event: ' . \$webhook->event_type . PHP_EOL;
    } else {
        echo '  ⚠️  Webhook no encontrado' . PHP_EOL;
    }
    
    echo PHP_EOL . '👥 USERS:' . PHP_EOL;
    \$user = App\Models\User::where('email', '${EMAIL}')->first();
    if (\$user) {
        echo '  ✅ Usuario creado/encontrado' . PHP_EOL;
        echo '  ✅ Email: ' . \$user->email . PHP_EOL;
        echo '  ✅ Moodle ID: ' . (\$user->moodle_user_id ?? 'Pending') . PHP_EOL;
        echo '  ✅ Order ID: ' . (\$user->medusa_order_id ?? 'N/A') . PHP_EOL;
    } else {
        echo '  ⏳ Usuario en cola de procesamiento' . PHP_EOL;
    }
    
    echo PHP_EOL . '🔄 JOBS:' . PHP_EOL;
    \$jobCount = DB::table('jobs')->count();
    echo '  Jobs pendientes: ' . \$jobCount . PHP_EOL;
    
    \$failedCount = DB::table('failed_jobs')->count();
    echo '  Jobs fallidos: ' . \$failedCount . PHP_EOL;
    "
    
elif [ "$HTTP_CODE" = "200" ]; then
    echo -e "${YELLOW}⚠️  Webhook recibido pero posible duplicado o usuario existente${NC}"
    echo -e "   Revisa el mensaje de respuesta arriba"
else
    echo -e "${RED}❌ ERROR: Webhook rechazado${NC}"
    echo -e "   HTTP Status: $HTTP_CODE"
fi

echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}\n"

echo -e "${YELLOW}💡 Para ver logs detallados:${NC}"
echo "   docker exec laravel-app tail -f storage/logs/laravel.log"
echo ""
echo -e "${YELLOW}💡 Para monitorear la cola:${NC}"
echo "   docker logs -f laravel-worker"