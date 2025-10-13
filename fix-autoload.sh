#!/bin/bash

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}  🔧 Reparando Autoload${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}\n"

# Verificar que estemos en el directorio correcto
if [ ! -d "src" ]; then
    echo -e "${YELLOW}⚠️  No se encuentra la carpeta 'src'${NC}"
    echo -e "${YELLOW}Ejecuta este script desde la raíz del proyecto${NC}"
    exit 1
fi

# Método 1: Usar composer localmente
echo -e "${YELLOW}1️⃣  Intentando regenerar autoload con Composer local...${NC}"
if command -v composer &> /dev/null; then
    cd src
    composer dump-autoload -o
    cd ..
    echo -e "${GREEN}✅ Autoload regenerado con Composer local${NC}\n"
else
    echo -e "${YELLOW}⚠️  Composer no encontrado localmente${NC}\n"
fi

# Método 2: Copiar Composer al contenedor y ejecutar
echo -e "${YELLOW}2️⃣  Instalando Composer en el contenedor...${NC}"

# Descargar Composer en el contenedor
docker exec laravel-app sh -c "curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer"

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✅ Composer instalado en el contenedor${NC}\n"
    
    # Ejecutar dump-autoload
    echo -e "${YELLOW}3️⃣  Regenerando autoload dentro del contenedor...${NC}"
    docker exec laravel-app composer dump-autoload -o
    echo -e "${GREEN}✅ Autoload regenerado${NC}\n"
else
    echo -e "${YELLOW}⚠️  No se pudo instalar Composer automáticamente${NC}\n"
fi

# Limpiar caches
echo -e "${YELLOW}4️⃣  Limpiando caches de Laravel...${NC}"
docker exec laravel-app php artisan config:clear > /dev/null 2>&1
docker exec laravel-app php artisan cache:clear > /dev/null 2>&1
docker exec laravel-app php artisan route:clear > /dev/null 2>&1
echo -e "${GREEN}✅ Caches limpiados${NC}\n"

# Reiniciar
echo -e "${YELLOW}5️⃣  Reiniciando contenedor...${NC}"
docker-compose restart app > /dev/null 2>&1
sleep 3
echo -e "${GREEN}✅ Contenedor reiniciado${NC}\n"

# Verificar
echo -e "${YELLOW}6️⃣  Verificando que la interfaz se cargue...${NC}"
if docker exec laravel-app php artisan tinker --execute="class_exists('App\Contracts\MoodleServiceInterface') ? print('OK') : print('FAIL');" 2>/dev/null | grep -q "OK"; then
    echo -e "${GREEN}✅ MoodleServiceInterface encontrada${NC}\n"
else
    echo -e "${YELLOW}⚠️  Aún no se encuentra la interfaz${NC}"
    echo -e "${YELLOW}Verifica que el archivo exista en: src/app/Contracts/MoodleServiceInterface.php${NC}\n"
fi

echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${GREEN}✅ Proceso completado${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}\n"

echo -e "${YELLOW}🧪 Próximo paso: ./test-webhook-improved.sh${NC}"