FROM php:8.2-apache

# Install system dependencies and GMP extension
RUN apt-get update && apt-get install -y \
    libgmp-dev \
    git \
    zip \
    unzip \
    openssl \
    && docker-php-ext-install gmp

# Enable Apache SSL and Rewrite modules
RUN a2enmod ssl rewrite

# Generate self-signed certificate for rp.local.conformance.test
RUN openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
    -keyout /etc/ssl/private/rp.local.conformance.test.key \
    -out /etc/ssl/certs/rp.local.conformance.test.pem \
    -subj "/C=US/ST=State/L=City/O=Organization/CN=rp.local.conformance.test"

# Copy SSL site configuration
COPY docker/rp-ssl.conf /etc/apache2/sites-available/rp-ssl.conf
RUN a2ensite rp-ssl

# Set up composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy the application source code
WORKDIR /var/www/html
COPY . /var/www/html/

# Install PHP dependencies
RUN composer install --no-dev --prefer-dist --optimize-autoloader

# Make sure index.php is loaded
COPY docker/rp-app/index.php /var/www/html/index.php

# Adjust permissions
RUN chown -R www-data:www-data /var/www/html
