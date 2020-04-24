FROM php:fpm-alpine

LABEL maintainer="Richard Weinhold <docker@ricwein.com>"
LABEL name="php-fpm-index"

ARG timezone

# set default timezone
RUN echo 'date.timezone = ${timezone:-Europe/Berlin}' > /usr/local/etc/php/conf.d/55-date.ini

# fixes www-data user uid/gid to fix later volume bindings permissions
RUN sed -ri 's/^www-data:x:82:82:/www-data:x:1000:50:/' /etc/passwd

# load our custom php.ini config
COPY docker/config/php.ini /usr/local/etc/php/

# install runtime-dependencies
RUN apk add --update --no-cache --virtual .persistent-deps \
    libpng freetype libjpeg-turbo libzip-dev zlib-dev

# install php extensions via pecl: redis, ext-zip
RUN apk add --update --no-cache --virtual .build-deps autoconf g++ make libxml2-dev libpng-dev freetype-dev libjpeg-turbo-dev \
    && docker-php-ext-configure opcache --enable-opcache && docker-php-ext-install opcache \
    && docker-php-ext-configure zip && docker-php-ext-install zip \
    && docker-php-ext-configure gd && docker-php-ext-install gd \
    && pecl install redis && docker-php-ext-enable redis \
    && apk del .build-deps && rm -rf /var/cache/apk/* /tmp/pear/ /tmp/*

# install composer
RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
    && php composer-setup.php --filename=composer --install-dir=/bin \
    && unlink composer-setup.php
ENV PATH /root/.composer/vendor/bin:$PATH

RUN mkdir /index && chown -R www-data:www-data /index

# copy our application to the image
COPY --chown=www-data:www-data . /application
WORKDIR /application
RUN mkdir -p var/{log,cache} && chown -R www-data:www-data var

# install composer dependencies
RUN composer install --no-dev
