DOCKER_COMPOSE=DOCKER_BUILDKIT=1 docker-compose -f docker/docker-compose.yml --env-file .env
DOCKER_COMPOSE_PROD=DOCKER_BUILDKIT=1 docker-compose -f docker/docker-compose.yml -f docker/docker-compose.prod.yml --env-file .env

.PHONY: start stop kill logs cli supervisor cron

start:
	$(DOCKER_COMPOSE_PROD) up --build --remove-orphans --detach --force-recreate

stop:
	$(DOCKER_COMPOSE_PROD) stop

kill:
	$(DOCKER_COMPOSE_PROD) kill
	$(DOCKER_COMPOSE_PROD) down --volumes --remove-orphans

cli:
	$(DOCKER_COMPOSE_PROD) exec cli bash

supervisor:
	$(DOCKER_COMPOSE_PROD) exec supervisor bash

cron:
	$(DOCKER_COMPOSE_PROD) exec cron bash

.PHONY: fixtures
fixtures: stop
fixtures:
	FIXTURES=1 $(DOCKER_COMPOSE) up --build --remove-orphans --detach --force-recreate