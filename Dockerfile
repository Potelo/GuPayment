FROM php:8.2-cli

RUN apt-get update && \
    apt-get install -y \
        git \
        zip \
        unzip \
        libzip-dev && \
    docker-php-ext-install zip

COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.json

RUN composer install -q --no-ansi --no-interaction --no-scripts --no-progress --prefer-dist

COPY . /var/www/html

CMD ["composer", "test"]