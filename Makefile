# XPR Suite — commandes de développement (depuis la racine du monorepo)

COMPOSE = docker compose -f xpr-infrastructure/docker-compose.yml

.PHONY: up down logs ps back-install back-test back-lint back-analyse front-install front-dev front-lint migrate fresh

up: ## Démarre l'environnement complet
	$(COMPOSE) up -d --build

down:
	$(COMPOSE) down

logs:
	$(COMPOSE) logs -f --tail=100

ps:
	$(COMPOSE) ps

migrate:
	$(COMPOSE) exec php php artisan migrate

fresh: ## Reconstruit la base avec les seeders (DEV UNIQUEMENT)
	$(COMPOSE) exec php php artisan migrate:fresh --seed

back-install:
	$(COMPOSE) exec php composer install

back-test:
	cd xpr-backend && php artisan test

back-lint:
	cd xpr-backend && ./vendor/bin/pint

back-analyse:
	cd xpr-backend && ./vendor/bin/phpstan analyse

front-install:
	cd xpr-frontend && npm install

front-dev:
	cd xpr-frontend && npm run dev

front-lint:
	cd xpr-frontend && npm run lint && npx tsc --noEmit
