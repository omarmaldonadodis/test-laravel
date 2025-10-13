#!/bin/bash

GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}  🧪 Test de Webhook + HMAC + Jobs${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}\n"

# ─── URL del webhook ─────────────────────────────────
WEBHOOK_URL="http://localhost:8080/api/webhooks/medusa/order-paid"

# ─── Email único para esta prueba ────────────────────
TIMESTAMP=$(date +%s)
TEST_EMAIL="test${TIMESTAMP}@example.com"

# ─── Payload de prueba ──────────────────────────────
PAYLOAD=$(cat <<EOF
{
  "id": "order_test_${TIMESTAMP}",
  "customer": {
    "email": "${TEST_EMAIL}",
    "first_name": "Test",
    "last_name": "User",
    "id": "cust_${TIMESTAMP}"
  },
  "items": [
    {
      "id": "item_1",
      "title": "Curso de Laravel Avanzado",
      "metadata": {
        "moodle_course_id": 2
      }
    }
  ],
  "metadata": {}
}
EOF
)

# ─── Secreto de Medusa ─────────────────────────────
SECRET="Wp7WCBqjZ3R6qdIix/lm3fsVjgU5aqs4mThbNmiZk1g="

echo -e "${YELLOW}📧 Email de prueba: ${TEST_EMAIL}${NC}"
echo -e "${YELLOW}🔑 Secreto usado para HMAC: $SECRET${NC}\n"

# ─── Mostrar payload antes de generar firma ─────────
echo -e "${BLUE}📄 Payload que se usará para generar la firma:${NC}"
echo "$PAYLOAD" | jq .
echo ""

# ─── Generar firma HMAC ────────────────────────────
SIGNATURE=$(echo -n "$PAYLOAD" | openssl dgst -sha256 -hmac "$SECRET" | sed 's/^.* //')
echo -e "${BLUE}🔑 Firma HMAC generada:${NC} $SIGNATURE"
echo ""

# ─── Mostrar header que se enviará ────────────────
echo -e "${BLUE}📤 Header que se enviará:${NC}"
echo "x-medusa-signature: $SIGNATURE"
echo ""

# ─── Confirmación antes de enviar ──────────────────
read -p "Presiona ENTER para enviar el webhook..." dummy

# ─── Enviar request ───────────────────────────────
RESPONSE=$(curl -s -w "\nHTTP_CODE:%{http_code}" -X POST "$WEBHOOK_URL" \
  -H "Content-Type: application/json" \
  -H "x-medusa-signature: $SIGNATURE" \
  -d "$PAYLOAD")

# ─── Extraer código HTTP ───────────────────────────
HTTP_CODE=$(echo "$RESPONSE" | grep "HTTP_CODE" | cut -d: -f2)
BODY=$(echo "$RESPONSE" | sed '/HTTP_CODE/d')

# ─── Mostrar respuesta ─────────────────────────────
echo -e "${BLUE}📥 Respuesta del webhook:${NC}"
echo "$BODY" | jq . 2>/dev/null || echo "$BODY"
echo ""

# ─── Verificar código ──────────────────────────────
if [ "$HTTP_CODE" = "202" ]; then
    echo -e "${GREEN}✅ Webhook aceptado (HTTP 202 Accepted)${NC}\n"
else
    echo -e "${YELLOW}⚠️  Código HTTP inesperado: $HTTP_CODE${NC}\n"
    echo -e "${YELLOW}💡 Revisa los logs del contenedor app:${NC}"
    echo "   docker-compose logs app"
    exit 1
fi
