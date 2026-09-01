FROM php:8.2-apache

# Install PostgreSQL PHP extensions so we can connect to Supabase
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Copy all project files to Apache's document root
COPY . /var/www/html/

# Set correct permissions
RUN chown -R www-data:www-data /var/www/html/

# Create an index.php to automatically redirect to the landing page
RUN echo '<?php header("Location: landing.php"); exit; ?>' > /var/www/html/index.php

EXPOSE 80
