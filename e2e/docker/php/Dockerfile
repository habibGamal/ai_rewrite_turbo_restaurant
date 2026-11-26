# One small PHP image shared by the POS, the website, their queue workers and the installer.
FROM php:8.4-cli-alpine

# Optional: a proxy that re-signs TLS can drop its CA as docker/php/certs/*.crt (git-ignored).
COPY certs/ /usr/local/share/ca-certificates/
RUN cat /usr/local/share/ca-certificates/*.crt >> /etc/ssl/certs/ca-certificates.crt 2>/dev/null || true

COPY --from=mlocati/php-extension-installer:2.7 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql mysqli bcmath gd intl zip exif pcntl sockets opcache \
    && apk add --no-cache bash git unzip mariadb-client tzdata \
    && cp /usr/share/zoneinfo/Africa/Cairo /etc/localtime && echo "Africa/Cairo" > /etc/timezone \
    && printf "memory_limit=1G\nupload_max_filesize=32M\npost_max_size=32M\ndate.timezone=Africa/Cairo\n" > /usr/local/etc/php/conf.d/e2e.ini

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY entrypoint.sh /usr/local/bin/e2e-entrypoint
RUN chmod +x /usr/local/bin/e2e-entrypoint

ENTRYPOINT ["e2e-entrypoint"]
