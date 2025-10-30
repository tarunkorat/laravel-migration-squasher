.PHONY: help test test-unit test-feature test-coverage test-filter install clean

help: ## Show this help message
	@echo 'Usage: make [target]'
	@echo ''
	@echo 'Available targets:'
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "  %-20s %s\n", $$1, $$2}' $(MAKEFILE_LIST)

install: ## Install dependencies
	composer install

test: ## Run all tests
	vendor/bin/phpunit

test-unit: ## Run unit tests only
	vendor/bin/phpunit tests/Unit

test-feature: ## Run feature tests only
	vendor/bin/phpunit tests/Feature

test-coverage: ## Run tests with coverage report
	vendor/bin/phpunit --coverage-html coverage
	@echo "Coverage report generated in coverage/index.html"

test-filter: ## Run specific test (usage: make test-filter FILTER=test_name)
	vendor/bin/phpunit --filter=$(FILTER)

test-verbose: ## Run tests with verbose output
	vendor/bin/phpunit --verbose

test-debug: ## Run tests with debug output
	vendor/bin/phpunit --debug

clean: ## Clean up generated files
	rm -rf vendor
	rm -rf coverage
	rm -rf .phpunit.cache
	rm -f composer.lock

format: ## Format code with PHP CS Fixer (if installed)
	@if [ -f vendor/bin/php-cs-fixer ]; then \
		vendor/bin/php-cs-fixer fix; \
	else \
		echo "PHP CS Fixer not installed. Run: composer require --dev friendsofphp/php-cs-fixer"; \
	fi

lint: ## Check code style
	@if [ -f vendor/bin/php-cs-fixer ]; then \
		vendor/bin/php-cs-fixer fix --dry-run --diff; \
	else \
		echo "PHP CS Fixer not installed. Run: composer require --dev friendsofphp/php-cs-fixer"; \
	fi

analyse: ## Run static analysis with PHPStan (if installed)
	@if [ -f vendor/bin/phpstan ]; then \
		vendor/bin/phpstan analyse src tests; \
	else \
		echo "PHPStan not installed. Run: composer require --dev phpstan/phpstan"; \
	fi
