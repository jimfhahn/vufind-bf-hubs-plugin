FROM php:8.3-apache

# Install system dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    git zip unzip default-jdk lsof curl \
    libicu-dev libxml2-dev libzip-dev libpng-dev libldap2-dev \
    mariadb-client \
    && docker-php-ext-install intl xml soap zip gd ldap pdo pdo_mysql mysqli \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Clone VuFind
ARG VUFIND_VERSION=v11.0.2
RUN git clone --depth 1 --branch ${VUFIND_VERSION} https://github.com/vufind-org/vufind.git /usr/local/vufind

WORKDIR /usr/local/vufind

# Install VuFind PHP dependencies
RUN composer install --no-interaction --no-dev

# Run VuFind installer (creates local dir, Apache config, etc.)
RUN php install.php --non-interactive --overridedir=/vufind-local \
    && chown -R www-data:www-data /vufind-local

# Configure Apache for VuFind
RUN ln -sf /vufind-local/httpd-vufind.conf /etc/apache2/conf-enabled/vufind.conf

# Set VuFind environment
ENV VUFIND_HOME=/usr/local/vufind
ENV VUFIND_LOCAL_DIR=/vufind-local
ENV VUFIND_LOCAL_MODULES=BibframeHub

# Suppress deprecation warnings that break output buffering
RUN echo "error_reporting = E_ALL & ~E_DEPRECATED & ~E_NOTICE" > /usr/local/etc/php/conf.d/vufind.ini \
    && echo "date.timezone = UTC" >> /usr/local/etc/php/conf.d/vufind.ini

# Copy entrypoint
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80 8983

ENTRYPOINT ["/entrypoint.sh"]
