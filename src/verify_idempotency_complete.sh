#!/bin/bash

echo "🔍 VERIFICACIÓN COMPLETA DE IDEMPOTENCIA"
echo "========================================"
echo ""

# 1. Verificar estructura de BD
echo "1️⃣  Verificando estructura de base de datos..."
php artisan tinker --execute="
\$required = ['moodle_user_id', 'medusa_order_id', 'moodle_processed_at'];
\$columns = Schema::getColumnListing('users');
\$missing = array_diff(\$required, \$columns);
if (empty(\$missing)) {
    echo '✅ Todas las columnas requeridas existen' . PHP_EOL;
} else {
    echo '❌ Faltan columnas: ' . implode(', ', \$missing) . PHP_EOL;
    exit(1);
}
"

if [ $? -ne 0 ]; then
    echo "❌ Estructura de BD incorrecta"
    exit 1
fi

# 2. Verificar Service existe
echo ""
echo "2️⃣  Verificando WebhookIdempotencyService..."
php artisan tinker --execute="
if (class_exists('App\Services\Webhook\WebhookIdempotencyService')) {
    echo '✅ Service existe y es cargable' . PHP_EOL;
} else {
    echo '❌ Service no encontrado' . PHP_EOL;
    exit(1);
}
"

if [ $? -ne 0 ]; then
    echo "❌ Service no encontrado"
    exit 1
fi

# 3. Ejecutar test rápido
echo ""
echo "3️⃣  Ejecutando tests rápidos..."
php test_idempotency_quick.php

if [ $? -ne 0 ]; then
    echo "❌ Tests fallaron"
    exit 1
fi

# 4. Test de integración
echo ""
echo "4️⃣  Ejecutando tests de integración..."
php test_idempotency_integration.php

if [ $? -ne 0 ]; then
    echo "❌ Tests de integración fallaron"
    exit 1
fi

echo ""
echo "========================================"
echo "🎉 VERIFICACIÓN COMPLETA EXITOSA"
echo "✅ Idempotencia funcionando correctamente"
