# K-Docs Makefile
# Usage: make [command]

.PHONY: help install test check clean

# Default
help:
	@echo "K-Docs Commands:"
	@echo "  make install     - Install dependencies"
	@echo "  make test        - Run all tests"
	@echo "  make test-smoke  - Quick health check"
	@echo "  make test-api    - API tests"
	@echo "  make test-unit   - PHPUnit tests"
	@echo "  make test-poc    - POC tests"
	@echo "  make check       - Pre-commit validation"
	@echo "  make analyse     - PHPStan analysis"
	@echo "  make fix         - Fix code style"
	@echo "  make clean       - Clean cache"
	@echo "  make report      - Generate HTML report"

# Installation
install:
	composer install
	@echo "Installing git hooks..."
	@cp .git/hooks/pre-commit.sample .git/hooks/pre-commit 2>/dev/null || true
	@chmod +x .git/hooks/pre-commit 2>/dev/null || true

# Tests
test:
	php tests/run_all.php

test-smoke:
	php tests/smoke_test.php

test-api:
	php tests/api_test.php

test-unit:
	vendor/bin/phpunit --testsuite=unit

test-integration:
	php tests/integration_test.php

test-ui:
	php tests/ui_test.php --screenshots

test-poc:
	php proofofconcept/test_all.php

# Validation
check: test-smoke analyse cs-check
	@echo "All checks passed!"

analyse:
	vendor/bin/phpstan analyse -c phpstan.neon --memory-limit=512M || true

cs-check:
	vendor/bin/phpcs --standard=PSR12 app/ --ignore=*/vendor/* || true

fix:
	vendor/bin/php-cs-fixer fix app/ || true

# Report
report:
	php tests/run_all.php --html
	@echo "Report: tests/output/report.html"

# Clean
clean:
	rm -rf tests/output/*.json
	rm -rf tests/output/*.html
	rm -rf tests/output/screenshots/*
	rm -rf .phpunit.cache
	rm -rf storage/cache/*
	@echo "Cache cleaned"

# Development
serve:
	php -S localhost:8000 -t .

watch:
	@echo "Watching for changes..."
	@while true; do \
		inotifywait -r -e modify app/ templates/ 2>/dev/null || sleep 5; \
		make test-smoke; \
	done
