# SRI-Plugin OJS sandbox image: Apache + PHP + a downloaded OJS release.

FROM php:8.1-apache

# OJS needs pdo_mysql (or pdo_pgsql) + a handful of common extensions.
RUN docker-php-ext-install pdo pdo_mysql mysqli \
    && a2enmod rewrite headers

# OJS release to install (override with --build-arg OJS_VERSION=3.3.0-22).
ARG OJS_VERSION=3.5.0

# Download & extract OJS into the webroot.
COPY install-ojs.sh /usr/local/bin/install-ojs.sh
RUN chmod +x /usr/local/bin/install-ojs.sh \
    && /usr/local/bin/install-ojs.sh "$OJS_VERSION"

RUN chown -R www-data:www-data /var/www/html
WORKDIR /var/www/html

# Convenience marker; the OJS release zip (https://pkp.sfu.ca/software/ojs/
# download) is the source of the version string used by install-ojs.sh.
ENV OJS_VERSION=${OJS_VERSION}
