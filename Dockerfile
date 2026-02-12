FROM php:8.2-apache

# Install Postgres PDO driver
RUN apt-get update && apt-get install -y libpq-dev \
  && docker-php-ext-install pdo pdo_pgsql \
  && rm -rf /var/lib/apt/lists/*

# Apache config
RUN a2enmod rewrite headers

# Copy your bot file
COPY index.php /var/www/html/index.php

# Permissions (optional but safe)
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
