FROM composer:2.10.3 AS composer

FROM php:8.5.10-fpm-bookworm

ARG APP_UID=1000
ARG APP_GID=1000

RUN apt-get update \
    && apt-get install --yes --no-install-recommends \
        $PHPIZE_DEPS \
        curl \
        git \
        libfcgi-bin \
        libicu-dev \
        libicu72 \
        libzip-dev \
        libzip4 \
        unzip \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        pcntl \
        pdo_mysql \
        zip \
    && pecl install redis-6.3.0 \
    && docker-php-ext-enable redis \
    && apt-get purge --yes --auto-remove \
        $PHPIZE_DEPS \
        libicu-dev \
        libzip-dev \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-application.ini
COPY docker/php-fpm/zz-application.conf /usr/local/etc/php-fpm.d/zz-application.conf

ENV COMPOSER_HOME=/tmp/composer

RUN groupadd --gid "${APP_GID}" app \
    && useradd --uid "${APP_UID}" --gid "${APP_GID}" --create-home --shell /bin/bash app \
    && mkdir -p /tmp/composer/cache /var/www/html \
    && chown -R app:app /tmp/composer /var/www/html

WORKDIR /var/www/html

USER app

CMD ["php-fpm"]
