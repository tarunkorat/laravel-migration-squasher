#!/bin/bash

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}╔════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║     Laravel Migration Squasher - Test Suite           ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════════╝${NC}"
echo ""

# Check if vendor directory exists
if [ ! -d "vendor" ]; then
    echo -e "${YELLOW}⚠️  vendor directory not found. Running composer install...${NC}"
    composer install
fi

echo -e "${BLUE}📦 Running all tests...${NC}"
echo ""

# Run all tests
vendor/bin/phpunit

TEST_EXIT_CODE=$?

echo ""

if [ $TEST_EXIT_CODE -eq 0 ]; then
    echo -e "${GREEN}✅ All tests passed!${NC}"
else
    echo -e "${RED}❌ Some tests failed!${NC}"
fi

echo ""
echo -e "${BLUE}╔════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║              Additional Test Commands                  ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "Run specific test suite:"
echo -e "  ${YELLOW}vendor/bin/phpunit tests/Unit${NC}"
echo -e "  ${YELLOW}vendor/bin/phpunit tests/Feature${NC}"
echo ""
echo -e "Run specific test file:"
echo -e "  ${YELLOW}vendor/bin/phpunit tests/Unit/MigrationAnalyzerTest.php${NC}"
echo ""
echo -e "Run with coverage:"
echo -e "  ${YELLOW}vendor/bin/phpunit --coverage-html coverage${NC}"
echo ""
echo -e "Run with filter:"
echo -e "  ${YELLOW}vendor/bin/phpunit --filter=it_gets_all_migration_files${NC}"
echo ""

exit $TEST_EXIT_CODE
