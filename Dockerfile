# Pinakes ILS — single-image Docker build (PHP 8.4 + Apache / mod_php).
#
# The image is built FROM a published Pinakes *release ZIP* (which already
# ships vendor/ via composer --no-dev), so the running image is byte-for-byte
# the artifact end users deploy — no source duplication, no composer at build.
#
#   docker build --build-arg PINAKES_VERSION=0.7.22 -t pinakes .
#
# Apache (not fpm+nginx) is deliberate: upstream prod is Apache-only and the
# release ships public/.htaccess (mod_rewrite) that works out of the box.

# Base image is the #1 source of system CVEs: a months-old *-bookworm snapshot
# drags along dozens of unpatched libs. Track the latest stable PHP on Debian
# trixie AND dist-upgrade below (the base tag is a snapshot and lags the security
# archive even when it's "latest"). Bump PHP_IMAGE to move up (e.g. php:8.5-apache-trixie).
ARG PHP_IMAGE=php:8.4-apache-trixie
FROM ${PHP_IMAGE} AS base

ARG PINAKES_VERSION=0.7.22
ENV PINAKES_VERSION=${PINAKES_VERSION}

LABEL org.opencontainers.image.title="Pinakes ILS" \
      org.opencontainers.image.description="Self-hosted Integrated Library System (Pinakes) — Apache + PHP 8.4, headless-installable." \
      org.opencontainers.image.url="https://github.com/fabiodalez-dev/Pinakes" \
      org.opencontainers.image.source="https://github.com/fabiodalez-dev/pinakes-docker" \
      org.opencontainers.image.licenses="GPL-3.0-only" \
      org.opencontainers.image.version="${PINAKES_VERSION}"

# --- System libraries + PHP extensions -------------------------------------
# json / curl / openssl / fileinfo are already bundled+enabled in the php:8.4
# base image — installing them again would error. We add only what Pinakes
# uses at runtime: mysqli + pdo_mysql (DB), mbstring, zip (uploads/backups/
# updater), gd (covers), intl (IntlDateFormatter), opcache (perf).
RUN set -eux; \
    apt-get update; \
    # dist-upgrade: the base tag is a point-in-time snapshot and lags behind the
    # Debian security archive even when it's the latest — pull the current patched
    # system packages so the published image doesn't ship known-fixed CVEs.
    apt-get -y dist-upgrade; \
    apt-get install -y --no-install-recommends \
        libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
        libonig-dev libicu-dev \
        unzip curl default-mysql-client; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" mysqli pdo_mysql mbstring zip gd intl opcache; \
    a2enmod rewrite headers expires deflate; \
    rm -rf /var/lib/apt/lists/*

# --- Application source (from the verified release ZIP) --------------------
# Locate the app root by the directory that contains composer.json, so we are
# robust to both the wrapped (pinakes-vX.Y.Z/) and flat ZIP layouts.
RUN set -eux; \
    base="https://github.com/fabiodalez-dev/Pinakes/releases/download/v${PINAKES_VERSION}"; \
    zipname="pinakes-v${PINAKES_VERSION}.zip"; \
    echo "Downloading ${base}/${zipname}"; \
    curl -fSL "${base}/${zipname}" -o "/tmp/${zipname}"; \
    # Integrity: verify against the .sha256 sidecar that create-release.sh
    # publishes next to the ZIP (bare filename inside, so check from /tmp).
    curl -fSL "${base}/${zipname}.sha256" -o "/tmp/${zipname}.sha256"; \
    ( cd /tmp && sha256sum -c "${zipname}.sha256" ); \
    mkdir -p /tmp/pinakes-extract; \
    unzip -q "/tmp/${zipname}" -d /tmp/pinakes-extract; \
    approot="$(dirname "$(find /tmp/pinakes-extract -maxdepth 2 -name composer.json | head -1)")"; \
    test -n "$approot"; \
    test -f "$approot/public/index.php"; \
    test -f "$approot/vendor/autoload.php"; \
    rm -rf /var/www/html; \
    mkdir -p /var/www/html; \
    cp -a "$approot/." /var/www/html/; \
    rm -rf "/tmp/${zipname}" "/tmp/${zipname}.sha256" /tmp/pinakes-extract; \
    # vendor must be production-clean (no phpstan refs) — fail loudly otherwise
    ! grep -q "phpstan" /var/www/html/vendor/composer/autoload_static.php; \
    # Seed the bundled plugins to a path OUTSIDE the storage volume. Docker only
    # populates a named volume from the image ONCE (at first creation), and a bind
    # mount hides the image content entirely — so without a re-sync the bundled
    # plugins never update on image upgrades and vanish under a bind mount. The
    # entrypoint rsyncs this seed into storage/plugins on every boot.
    mkdir -p /opt/pinakes/storage-seed; \
    cp -a /var/www/html/storage/plugins /opt/pinakes/storage-seed/plugins; \
    cp -a /var/www/html/storage/.htaccess /opt/pinakes/storage-seed/.htaccess 2>/dev/null || true

# --- Config + entrypoint + headless installer ------------------------------
COPY config/php-custom.ini /usr/local/etc/php/conf.d/zz-pinakes.ini
COPY config/apache-pinakes.conf /etc/apache2/sites-available/000-default.conf
COPY config/headless-install.php /usr/local/lib/pinakes/headless-install.php
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Writable runtime directories (also (re)created by the entrypoint on volumes).
RUN set -eux; \
    mkdir -p \
      /var/www/html/storage/sessions \
      /var/www/html/storage/logs \
      /var/www/html/storage/cache \
      /var/www/html/storage/backups \
      /var/www/html/storage/calendar \
      /var/www/html/storage/uploads \
      /var/www/html/storage/tmp \
      /var/www/html/storage/plugins \
      /var/www/html/storage/rate_limits \
      /var/www/html/public/uploads/copertine \
      /var/www/html/public/uploads/autori \
      /var/www/html/public/uploads/events \
      /var/www/html/public/uploads/digital \
      /var/www/html/public/uploads/archives \
      /var/www/html/cache /var/www/html/tmp; \
    chown -R www-data:www-data /var/www/html; \
    chmod -R u+rwX,g+rwX /var/www/html/storage /var/www/html/public/uploads

WORKDIR /var/www/html

# HTTP-based healthcheck: Apache+mod_php serves directly, so a 200/302 on / means alive.
HEALTHCHECK --interval=15s --timeout=5s --start-period=90s --retries=5 \
  CMD curl -fsS -o /dev/null -w '%{http_code}' http://127.0.0.1/ | grep -qE '^(200|302)$' || exit 1

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["apache2-foreground"]
