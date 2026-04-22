# TypeDock developer Makefile.
# Thin wrappers over `docker compose` plus a few local fallbacks.

COMPOSE ?= docker compose

.PHONY: help dev dev-mysql dev-postgres down shell install migrate test dist assets assets-watch

help: ## Show available targets
	@echo "TypeDock make targets:"
	@echo "  make dev           - Start app + nginx (SQLite). http://localhost:8080"
	@echo "  make dev-mysql     - Start app + nginx + mysql"
	@echo "  make dev-postgres  - Start app + nginx + postgres"
	@echo "  make down          - Stop and remove the compose stack"
	@echo "  make shell         - Open a shell inside the app container"
	@echo "  make install       - Run cli/install.php (in container, falls back to host)"
	@echo "  make migrate       - Run database migrations"
	@echo "  make test          - Run PHPUnit"
	@echo "  make assets        - Build admin CSS (Tailwind 4)"
	@echo "  make assets-watch  - Rebuild admin CSS on change"
	@echo "  make dist          - Build the shared-hosting distribution zip"

dev: ## Start app + nginx (default, SQLite)
	$(COMPOSE) up -d app nginx
	@echo "TypeDock is up: http://localhost:8080"

dev-mysql: ## Start app + nginx + mysql
	$(COMPOSE) --profile mysql up -d
	@echo "TypeDock (MySQL) is up: http://localhost:8080"

dev-postgres: ## Start app + nginx + postgres
	$(COMPOSE) --profile postgres up -d
	@echo "TypeDock (Postgres) is up: http://localhost:8080"

down: ## Stop the stack
	$(COMPOSE) down

shell: ## Open a shell in the app container
	$(COMPOSE) exec app sh

install: ## Run initial installer
	@if $(COMPOSE) ps --services --status running | grep -q '^app$$'; then \
		$(COMPOSE) exec app php cli/install.php; \
	else \
		echo "app container not running — falling back to local PHP"; \
		php cli/install.php; \
	fi

migrate: ## Run database migrations
	$(COMPOSE) exec app php cli/migrate.php migrate

test: ## Run the PHPUnit test suite
	$(COMPOSE) exec app vendor/bin/phpunit

assets: ## Build admin CSS (Tailwind 4, core-developer only — distribution ships prebuilt)
	npm install --silent
	npm run build:css

assets-watch: ## Rebuild admin CSS on change
	npm run watch:css

dist: ## Build the shared-hosting distribution zip
	bash build/make-shared-zip.sh
