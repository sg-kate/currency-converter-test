.DEFAULT_GOAL := help
.PHONY: help bootstrap up down reset build ps logs db wp composer test lint lint-fix screenshots

## Show this help
help:
	@awk 'BEGIN {FS = ":.*##"; printf "\nTargets:\n"} \
		/^[a-zA-Z_-]+:.*?##/ { printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2 } \
		END { printf "\n" }' $(MAKEFILE_LIST)

bootstrap: ## Install dependencies, start the stack, install WordPress (idempotent)
	@./bin/bootstrap.sh

up: ## Start containers without touching the installation
	@docker compose up -d --wait db
	@docker compose up -d app cron

down: ## Stop containers, keep the data
	@docker compose down

reset: ## Destroy the database and dependencies, then bootstrap from scratch
	@./bin/reset.sh

build: ## Rebuild the application image
	@docker compose build app

ps: ## Show container status
	@docker compose ps

logs: ## Follow logs (make logs S=cron to pick a service)
	@docker compose logs -f $(S)

db: ## Open a MariaDB shell
	@docker compose exec db sh -c 'exec mariadb -u"$$MARIADB_USER" -p"$$MARIADB_PASSWORD" "$$MARIADB_DATABASE"'

wp: ## Run WP-CLI: make wp CMD="plugin list"
	@./bin/wp $(CMD)

composer: ## Run Composer: make composer CMD="require some/package"
	@./bin/composer $(CMD)

screenshots: ## Capture admin screenshots into docs/screenshots
	@node .claude/skills/browser-capture/scripts/capture.mjs

test: ## Run PHPUnit inside the app container
	@docker compose exec -T app vendor/bin/phpunit $(ARGS)

lint: ## Check coding standards
	@./bin/composer lint

lint-fix: ## Fix what the coding standards can fix automatically
	@./bin/composer lint:fix
