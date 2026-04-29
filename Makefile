# TypeDock developer Makefile.
# Thin wrappers over `docker compose` plus a few local fallbacks.

COMPOSE ?= docker compose

.PHONY: help dev dev-mysql dev-postgres down shell install migrate test dist assets assets-watch security-scan security-scan-full security-scan-admin

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
	@echo "  make assets        - Build admin CSS (Tailwind 4) + editor bundle (esbuild)"
	@echo "  make assets-watch  - Rebuild admin CSS on change"
	@echo "  make dist          - Build the shared-hosting distribution zip"
	@echo "  make security-scan       - OWASP ZAP baseline (passive) scan via docker compose"
	@echo "  make security-scan-full  - OWASP ZAP Automation Framework (active) scan, public surface"
	@echo "  make security-scan-admin - OWASP ZAP authenticated active scan against /admin (CSRF bypass; see docker.env.example)"

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

assets: ## Build admin CSS (Tailwind 4) + editor bundle (esbuild). Core-developer only — distribution ships prebuilt.
	npm install --silent
	npm run build

assets-watch: ## Rebuild admin CSS on change (editor bundle: run `npm run watch:editor` separately if needed)
	npm run watch:css

dist: ## Build the shared-hosting distribution zip
	bash build/make-shared-zip.sh

security-scan: ## OWASP ZAP baseline (passive) scan against the compose stack
	bash bin/security-scan.sh baseline

security-scan-full: ## OWASP ZAP Automation Framework (active) scan — do NOT point at production
	bash bin/security-scan.sh full

security-scan-admin: ## Authenticated active scan against /admin/* — requires CSRF bypass env vars in docker.env
	bash bin/security-scan.sh admin
