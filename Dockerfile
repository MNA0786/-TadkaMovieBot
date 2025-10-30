FROM php:8.1-apache

# System dependencies install karo
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    ffmpeg \
    libimage-exiftool-perl \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# Composer install karo
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# PHP configuration for large files
RUN echo "upload_max_filesize = 2000M" >> /usr/local/etc/php/php.ini \
    && echo "post_max_size = 2000M" >> /usr/local/etc/php/php.ini \
    && echo "memory_limit = 512M" >> /usr/local/etc/php/php.ini \
    && echo "max_execution_time = 300" >> /usr/local/etc/php/php.ini \
    && echo "max_input_time = 300" >> /usr/local/etc/php/php.ini \
    && echo "display_errors = Off" >> /usr/local/etc/php/php.ini \
    && echo "log_errors = On" >> /usr/local/etc/php/php.ini

# Working directory set karo
WORKDIR /var/www/html

# Apache configuration
RUN a2enmod rewrite headers
COPY .htaccess /var/www/html/.htaccess

# Application files copy karo
COPY . .

# File permissions set karo
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

EXPOSE 80

CMD ["apache2-foreground"]
