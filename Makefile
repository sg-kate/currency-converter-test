.DEFAULT_GOAL := help
.PHONY: help bootstrap up down reset ps logs db wp

## Show this help
help:
	@awk 'BEGIN {FS = ":.*##"; printf "\nTargets:\n"} \
		/^[a-zA-Z_-]+:.*?##/ { printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2 } \
		END { printf "\n" }' $(MAKEFILE_LIST)

bootstrap: ## Bring the stack up and install WordPress (idempotent)
	@./bin/bootstrap.sh

up: ## Start containers without touching the installation
	@docker compose up -d --wait db
	@docker compose up -d wordpress cron

down: ## Stop containers, keep the data
	@docker compose down

reset: ## Destroy volumes and bootstrap from scratch
	@./bin/reset.sh

ps: ## Show container status
	@docker compose ps

logs: ## Follow logs (make logs S=cron to pick a service)
	@docker compose logs -f $(S)

db: ## Open a MariaDB shell
	@docker compose exec db sh -c 'exec mariadb -u"$$MARIADB_USER" -p"$$MARIADB_PASSWORD" "$$MARIADB_DATABASE"'

wp: ## Run WP-CLI: make wp CMD="plugin list"
	@./bin/wp $(CMD)
