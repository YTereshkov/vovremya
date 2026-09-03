FROM php:8.4-cli-bookworm

ARG HOST_UID=1000
ARG HOST_GID=1000
ARG PHP_REDIS_VERSION=6.2.0

RUN apt-get update \
    && apt-get install --no-install-recommends -y \
        $PHPIZE_DEPS \
        git \
        libicu-dev \
        libonig-dev \
        libpq-dev \
        libxml2-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-install -j"$(nproc)" dom intl mbstring opcache pcntl pdo_pgsql xml xmlwriter zip \
    && pecl install "redis-${PHP_REDIS_VERSION}" \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY --chmod=755 docker/php/dev-entrypoint.sh /usr/local/bin/vovremya-dev-entrypoint
COPY docker/php/conf.d/app.ini /usr/local/etc/php/conf.d/app.ini

RUN groupadd --gid "${HOST_GID}" app \
    && useradd --uid "${HOST_UID}" --gid app --create-home app

WORKDIR /app
USER app

ENTRYPOINT ["vovremya-dev-entrypoint"]
