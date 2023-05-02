#!/usr/bin/env bash

set -ex

/usr/local/bin/wait

# first arg is `-f` or `--some-option`
if [ "${1#-}" != "$1" ]; then
	set -- php "$@"
fi

if [ "$LOAD_FIXTURES" == 1 ]; then
  if [ "$FIXTURES_CHECK_EMAIL" == 1 ]; then
    php bin/fixtures -d --with-checked-emails
  else
    php bin/fixtures -d
  fi
fi

#if [ "$1" = 'php' ]; then
#  if [ "$LOAD_FIXTURES" == 1 ]; then
#    php bin/fixtures -d
#  fi
#fi

exec docker-php-entrypoint "$@"
