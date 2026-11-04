# One image for everything local and in CI-like runs: PHP 8.5 with the extensions Magento's framework needs (so the
# real framework installs for PHPStan), ext-opentelemetry and ext-protobuf (so the harness records real telemetry),
# and Composer. Test image only; it is never shipped.
FROM php:8.5-cli-bookworm
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/download/2.7.22/install-php-extensions /usr/local/bin/
RUN apt-get update && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions bcmath ftp gd intl soap sockets sodium xsl zip pdo_mysql opentelemetry protobuf
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ROOT_VERSION=dev-main COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /repo
