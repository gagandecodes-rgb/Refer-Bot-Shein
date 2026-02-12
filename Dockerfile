FROM php:8.2-apache
RUN apt-get update && apt-get install -y libpq-dev \
  && docker-php-ext-install pdo pdo_pgsql \
  && rm -rf /var/lib/apt/lists/*
RUN a2enmod rewrite headers
COPY index.php /var/www/html/index.php
COPY verify.php /var/www/html/verify.php
RUN chown -R www-data:www-data /var/www/html
EXPOSE 80
