#!/bin/bash

echo "🧪 Running Laravel Test Suite"
echo "=============================="
echo ""

# Colores
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Función para imprimir con color
print_status() {
    if [ $1 -eq 0 ]; then
        echo -e "${GREEN}✅ $2${NC}"
    else
        echo -e "${RED}❌ $2${NC}"
    fi
}

# 1. Tests Unitarios
echo -e "${YELLOW}📦 Running Unit Tests...${NC}"
php artisan test --testsuite=Unit --parallel
UNIT_RESULT=$?
print_status $UNIT_RESULT "Unit Tests"
echo ""

# 2. Tests de Integración
echo -e "${YELLOW}🔗 Running Feature Tests...${NC}"
php artisan test --testsuite=Feature --parallel
FEATURE_RESULT=$?
print_status $FEATURE_RESULT "Feature Tests"
echo ""

# 3. Coverage Report
if [ "$1" = "--coverage" ]; then
    echo -e "${YELLOW}📊 Generating Coverage Report...${NC}"
    php artisan test --coverage --min=80
    COVERAGE_RESULT=$?
    print_status $COVERAGE_RESULT "Coverage Report"
    echo ""
fi

# 4. Resumen Final
echo "=============================="
echo "📋 Test Summary:"
echo ""

if [ $UNIT_RESULT -eq 0 ] && [ $FEATURE_RESULT -eq 0 ]; then
    echo -e "${GREEN}🎉 All tests passed!${NC}"
    exit 0
else
    echo -e "${RED}⚠️  Some tests failed${NC}"
    [ $UNIT_RESULT -ne 0 ] && echo "  - Unit tests failed"
    [ $FEATURE_RESULT -ne 0 ] && echo "  - Feature tests failed"
    exit 1
fi