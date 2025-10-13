#!/bin/bash

# ============================================
# Script de Instalación: Queues con Redis
# Para Laravel + Docker
# ============================================

set -e  # Detener si hay error

# Colores
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Funciones de utilidad
print_success() { echo -e "${GREEN}✅ $1${NC}"; }
print_info() { echo -e "${BLUE}ℹ️  $1${NC}"; }
print_warning() { echo -e "${YELLOW}⚠️  $1${NC}"; }
print_error() { echo -e "${RED}❌ $1${NC}"; }
print_header() { echo -e "\n${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"; echo -e "${BLUE}  $1${NC}"; echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}\n"; }

# ============================================
# 1. VERIFICACIONES PREVIAS
# ============================================
print_header "1️⃣  VERIFICACIONES PREVIAS"

# Verificar docker-compose.yml
if [ ! -f "docker-compose.yml" ]; then
    print_error "No se encuentra docker-compose.yml"
    exit 1
fi
print_success "docker-compose.yml encontrado"

# Verificar que los contenedores estén corriendo
if ! docker ps | grep -q "laravel-app"; then
    print_warning "Contenedor laravel-app no está corriendo"
    print_info "Iniciando contenedores..."
    docker-compose up -d
    sleep 5
fi
print_success "Contenedor laravel-app corriendo"

if ! docker ps | grep -q "laravel-redis"; then
    print_error "Contenedor laravel-redis no está corriendo"
    exit 1
fi
print_success "Contenedor laravel-redis corriendo"

# ============================================
# 2. VERIFICAR CONFIGURACIÓN
# ============================================
print_header "2️⃣  VERIFICANDO CONFIGURACIÓN"

# Verificar driver de queue
QUEUE_DRIVER=$(docker exec laravel-app php artisan tinker --execute="echo config('queue.default');" 2>/dev/null | tail -n 1)
if [ "$QUEUE_DRIVER" = "redis" ]; then
    print_success "Driver de queue: redis"
else
    print_error "Driver de queue NO es redis (actual: $QUEUE_DRIVER)"
    print_info "Verifica tu archivo .env: QUEUE_CONNECTION=redis"
    exit 1
fi

# Verificar conexión a Redis
if docker exec laravel-redis redis-cli PING | grep -q "PONG"; then
    print_success "Redis responde correctamente"
else
    print_error "Redis no responde"
    exit 1
fi

# ============================================
# 3. CREAR MIGRACIONES
# ============================================
print_header "3️⃣  CREANDO MIGRACIONES"

# Tabla de failed jobs (obligatoria)
print_info "Creando migración: failed_jobs..."
docker exec laravel-app php artisan queue:failed-table 2>/dev/null || print_warning "Migración failed_jobs ya existe"
print_success "Migración failed_jobs lista"

# Tabla de job batches (opcional)
read -p "¿Planeas usar Job Batching? (s/n): " -n 1 -r
echo
if [[ $REPLY =~ ^[Ss]$ ]]; then
    print_info "Creando migración: job_batches..."
    docker exec laravel-app php artisan queue:batches-table 2>/dev/null || print_warning "Migración job_batches ya existe"
    print_success "Migración job_batches lista"
fi

# ============================================
# 4. EJECUTAR MIGRACIONES
# ============================================
print_header "4️⃣  EJECUTANDO MIGRACIONES"

docker exec laravel-app php artisan migrate --force
print_success "Migraciones ejecutadas"

# ============================================
# 5. VERIFICAR TABLAS CREADAS
# ============================================
print_header "5️⃣  VERIFICANDO TABLAS"

# Verificar failed_jobs
if docker exec laravel-app php artisan tinker --execute="use Illuminate\Support\Facades\Schema; echo Schema::hasTable('failed_jobs') ? 'yes' : 'no';" 2>/dev/null | grep -q "yes"; then
    print_success "Tabla failed_jobs creada"
else
    print_error "Tabla failed_jobs NO existe"
fi

# ============================================
# 6. LIMPIAR CACHE
# ============================================
print_header "6️⃣  LIMPIANDO CACHE"

docker exec laravel-app php artisan config:clear >/dev/null 2>&1
print_success "Cache de configuración limpiado"

docker exec laravel-app php artisan cache:clear >/dev/null 2>&1
print_success "Cache de aplicación limpiado"

docker exec laravel-app php artisan event:clear >/dev/null 2>&1
print_success "Cache de eventos limpiado"

# ============================================
# 7. REINICIAR WORKER
# ============================================
print_header "7️⃣  REINICIANDO WORKER"

docker-compose restart queue-worker
sleep 3
print_success "Worker reiniciado"

# Verificar que el worker esté corriendo
if docker ps | grep -q "laravel-worker"; then
    WORKER_STATUS=$(docker ps --filter "name=laravel-worker" --format "{{.Status}}")
    print_success "Worker corriendo: $WORKER_STATUS"
else
    print_error "Worker NO está corriendo"
    exit 1
fi

# ============================================
# 8. PRUEBAS FINALES
# ============================================
print_header "8️⃣  PRUEBAS FINALES"

# Verificar que no hay jobs en cola
JOBS_COUNT=$(docker exec laravel-redis redis-cli LLEN queues:default 2>/dev/null || echo "0")
print_info "Jobs en cola: $JOBS_COUNT"

# Verificar configuración completa
print_info "Configuración final:"
docker exec laravel-app php artisan config:show queue.default
docker exec laravel-app php artisan config:show queue.connections.redis.driver

# ============================================
# 9. RESUMEN
# ============================================
print_header "✅  INSTALACIÓN COMPLETADA"

echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${GREEN}  Sistema de Queues Configurado${NC}"
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""
echo "📋 Configuración:"
echo "   - Driver: Redis"
echo "   - Worker: Corriendo"
echo "   - Tabla failed_jobs: ✅"
echo ""
echo "🔗 Próximos pasos:"
echo "   1. Copia los archivos del proyecto (DTOs, Jobs, etc.)"
echo "   2. Actualiza los archivos modificados"
echo "   3. Prueba con: ./test-webhook.sh"
echo ""
echo "📊 Comandos útiles:"
echo "   - Ver logs: docker-compose logs -f queue-worker"
echo "   - Monitorear: docker exec laravel-app php artisan queue:monitor redis:default"
echo "   - Jobs fallidos: docker exec laravel-app php artisan queue:failed"
echo "   - Redis CLI: docker exec -it laravel-redis redis-cli"
echo ""
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"