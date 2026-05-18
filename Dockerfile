FROM php:8.2-apache-bookworm

WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libkrb5-dev \
        libc-client2007e-dev \
        libmagic-dev \
        libonig-dev \
        libpng-dev \
        libwebp-dev \
        libxml2-dev \
        libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-configure imap --with-kerberos --with-imap-ssl \
    && docker-php-ext-install -j"$(nproc)" \
        curl \
        fileinfo \
        gd \
        imap \
        mbstring \
        pdo_mysql \
        dom \
        simplexml \
        xmlreader \
        xmlwriter \
        zip \
    && a2enmod headers rewrite \
    && { \
        echo '<Directory /var/www/html>'; \
        echo '    Options FollowSymLinks'; \
        echo '    AllowOverride All'; \
        echo '    Require all granted'; \
        echo '    DirectoryIndex index.php index.html'; \
        echo '</Directory>'; \
    } > /etc/apache2/conf-available/taskflow.conf \
    && a2enconf taskflow \
    && rm -rf /var/lib/apt/lists/*

COPY . .

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/docker-entrypoint.sh \
    && chmod +x /usr/local/bin/docker-entrypoint.sh \
    && rm -f /var/www/html/docker-entrypoint.sh

RUN mkdir -p /var/www/html/uploads \
    /var/www/html/backups \
    /var/www/html/api/logs \
    /var/www/html/runtime \
    && chown -R www-data:www-data /var/www/html/uploads \
    /var/www/html/backups \
    /var/www/html/api/logs \
    /var/www/html/runtime

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]

EXPOSE 80
