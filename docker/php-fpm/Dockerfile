ARG PHP_VERSION=${PHP_VERSION:-8.2}


FROM php:${PHP_VERSION}-fpm-alpine

ARG APP_ENV=${APP_ENV:-prod}
ARG APP_INDEX_PATH_ARG=${APP_INDEX_PATH:-/index/}
ENV APP_INDEX_PATH=${APP_INDEX_PATH:-$APP_INDEX_PATH_ARG}

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

RUN apk add --update --no-cache acl fcgi file gettext gnu-libiconv
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions opcache intl redis zip @composer
RUN if [ "$APP_ENV" = "dev" ] ; then install-php-extensions xdebug ; fi

# install gnu-libiconv and set LD_PRELOAD env to make iconv work fully on Alpine image.
# see https://github.com/docker-library/php/issues/240#issuecomment-763112749
ENV LD_PRELOAD /usr/lib/preloadable_libiconv.so

WORKDIR /app
RUN mkdir -p var/cache var/log ${APP_INDEX_PATH}
COPY ./src/ ./src/
COPY ./config/ ./config/
COPY ./bin/ ./bin/
COPY ./public/ ./public/
COPY ./templates/ ./templates/
COPY ./composer.json ./composer.json
COPY ./composer.lock ./composer.lock
COPY ./.env ./.env
RUN chmod -R 777 var/cache var/log && sync

# https://getcomposer.org/doc/03-cli.md#composer-allow-superuser
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV PATH="${PATH}:/root/.composer/vendor/bin"
RUN composer install --prefer-dist --no-progress --no-interaction --optimize-autoloader --no-scripts --no-dev
RUN composer dump-autoload --classmap-authoritative --no-dev

COPY ./docker/php-fpm/xdebug.ini ${PHP_INI_DIR}/conf.d/zzzz_xdebug.ini
