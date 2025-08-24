FROM php:8.2-apache

WORKDIR /var/www/html

RUN set -eux; \
  apt-get update; \
  apt-get install -y --no-install-recommends \
  libicu-dev \
  libzip-dev \
  unzip \
  libpng-dev \
  libjpeg-dev \
  libfreetype6-dev \
  libonig-dev \
  git \
  curl \
  build-essential \
  pkg-config \
  autoconf \
  libssl-dev \
  libsasl2-dev; \
  docker-php-ext-configure gd --with-freetype --with-jpeg; \
  docker-php-ext-install -j"$(nproc)" intl zip mbstring exif gd opcache; \
  pecl install mongodb; \
  docker-php-ext-enable mongodb; \
  rm -rf /var/lib/apt/lists/*

COPY composer.json composer.lock ./
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
  && composer install --no-dev --prefer-dist --no-progress --optimize-autoloader

COPY . .

RUN a2enmod rewrite headers expires

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
  && { \
  echo 'opcache.enable=1'; \
  echo 'opcache.validate_timestamps=0'; \
  echo 'opcache.max_accelerated_files=20000'; \
  echo 'opcache.memory_consumption=192'; \
  echo 'opcache.interned_strings_buffer=16'; \
  echo 'opcache.preload_user=www-data'; \
  } >> /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini

RUN echo 'variables_order = "EGPCS"' > /usr/local/etc/php/conf.d/env-vars.ini

EXPOSE 80
