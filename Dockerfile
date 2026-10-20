FROM php:8.4-apache-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev libonig-dev libcurl4-openssl-dev curl \
 && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql gd mbstring opcache curl \
 && a2enmod rewrite headers \
 && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html
COPY config/apache.conf /etc/apache2/sites-available/000-default.conf
COPY config/php.ini /usr/local/etc/php/conf.d/production.ini
COPY . /var/www/html
RUN mkdir -p storage/uploads && chown -R www-data:www-data storage && chmod -R 750 storage
EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 CMD curl -fsS http://127.0.0.1/health || exit 1
