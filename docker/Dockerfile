# syntax=docker/dockerfile:1.4

ARG PHP_VERSION=7.4
ARG VERSION=${PHP_VERSION}-cli-alpine

FROM mlocati/php-extension-installer:latest AS php_extension_installer


FROM php:$VERSION as build

ARG FIXTURES
ARG FIXTURES_CHECK_EMAIL

COPY --from=php_extension_installer --link /usr/bin/install-php-extensions /usr/local/bin/

RUN apk add --no-cache \
        bash \
	;

RUN set -eux; \
    install-php-extensions \
        igbinary \
        pdo_mysql \
        pcntl \
    ;

ADD https://github.com/ufoscout/docker-compose-wait/releases/download/2.9.0/wait /usr/local/bin/wait
RUN chmod +x /usr/local/bin/wait

RUN set -eux; \
    mv /etc/profile.d/color_prompt.sh.disabled /etc/profile.d/color_prompt.sh; \
    rm /etc/profile.d/locale.sh; \
    cp /etc/bash/bashrc /root/.bashrc

COPY --link docker/docker-entrypoint.d/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

WORKDIR /app
COPY --link . ./
RUN rm -Rf docker/

ENV LOAD_FIXTURES=$FIXTURES
ENV FIXTURES_CHECK_EMAIL=$FIXTURES_CHECK_EMAIL
ENTRYPOINT ["entrypoint"]


FROM build as cli
CMD ["sleep", "infinity"]


FROM build AS cron
ADD https://github.com/aptible/supercronic/releases/download/v0.2.1/supercronic-linux-amd64 /usr/local/bin/supersonic
RUN chmod +x /usr/local/bin/supersonic
CMD ["supersonic", "/app/cron/crontab"]


FROM build AS supervisor
RUN apk update && \
  apk add --no-cache supervisor && \
  rm -rf /var/cache/apk/* \
    ;

RUN mkdir -p /var/log/supervisord && mkdir -p /etc/supervisor/conf.d

COPY --link docker/supervisor/supervisord.conf /etc/supervisord.conf
COPY --link docker/supervisor/check-email-worker.conf /etc/supervisor/conf.d/check-email-worker.conf

CMD ["supervisord", "-n", "-c", "/etc/supervisord.conf"]


