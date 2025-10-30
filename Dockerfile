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

# Working directory set karo
WORKDIR /var/www/html

# Apache configuration
RUN a2enmod rewrite
COPY .htaccess /var/www/html/.htaccess

# File permissions set karo
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 777 /var/www/html/backups

# Application files copy karo
COPY . .

# File permissions for data files
RUN touch users.json bot_stats.json popular_searches.json movies.csv \
    && chmod 666 users.json bot_stats.json popular_searches.json movies.csv \
    && chmod 777 backups

EXPOSE 80

CMD ["apache2-foreground"]