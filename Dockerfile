# WordPress + Apache + PHP 8.2 base
FROM wordpress:php8.2-apache

# Install system deps & PHP extensions you’ll likely want
# - less, vim-tiny: handy in a container
# - default-mysql-client: lets you run mysql CLI if needed
# - libpng/jpeg/zip: common for media & zip ops
RUN apt-get update && apt-get install -y --no-install-recommends \
    less vim-tiny default-mysql-client git unzip \
    libpng-dev libjpeg62-turbo-dev libzip-dev \
  && docker-php-ext-configure gd --with-jpeg \
  && docker-php-ext-install -j"$(nproc)" gd zip \
  && rm -rf /var/lib/apt/lists/*

# Enable Apache mods (pretty permalinks)
RUN a2enmod rewrite headers expires

# ---- WP-CLI ----
ENV WP_CLI_BIN=/usr/local/bin/wp
RUN curl -o /usr/local/bin/wp -L https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
  && chmod +x /usr/local/bin/wp \
  && wp --allow-root --version

# Optional: recommended PHP settings for WordPress dev
# (tune as you like; these are dev-friendly)
RUN { \
    echo 'file_uploads=On'; \
    echo 'memory_limit=256M'; \
    echo 'upload_max_filesize=64M'; \
    echo 'post_max_size=64M'; \
    echo 'max_execution_time=300'; \
  } > /usr/local/etc/php/conf.d/wordpress.ini

# Optional: Xdebug (uncomment to enable for step-debugging)
# RUN pecl install xdebug \
#   && docker-php-ext-enable xdebug \
#   && { \
#       echo 'zend_extension=/usr/local/lib/php/extensions/no-debug-non-zts-20220829/xdebug.so'; \
#       echo 'xdebug.mode=debug,develop'; \
#       echo 'xdebug.start_with_request=yes'; \
#       echo 'xdebug.discover_client_host=1'; \
#       echo 'xdebug.client_port=9003'; \
#     } > /usr/local/etc/php/conf.d/xdebug.ini

# Working directory stays as /var/www/html (WordPress root)
WORKDIR /var/www/html

# Copy custom entrypoint scripts for Trail Agent auto-setup
COPY docker-entrypoint-init.sh /usr/local/bin/
COPY docker-entrypoint-wrapper.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint-init.sh \
    && chmod +x /usr/local/bin/docker-entrypoint-wrapper.sh

# Use our wrapper entrypoint (which calls the original WordPress entrypoint)
ENTRYPOINT ["docker-entrypoint-wrapper.sh"]
CMD ["apache2-foreground"]