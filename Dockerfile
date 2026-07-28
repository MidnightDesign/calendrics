FROM node:24-slim AS node-builder
# Install acorn (JS AST parser) globally so we can copy just the package
RUN npm install -g acorn@8

FROM php:8.4-cli

# Copy Node.js runtime for the test262 transpiler
COPY --from=node:24-slim /usr/local/bin/node /usr/local/bin/node
# Copy acorn package (used by tools/transpile-test262.mjs)
COPY --from=node-builder /usr/local/lib/node_modules/acorn /usr/local/lib/node_modules/acorn

RUN apt-get update && apt-get install -y git rsync unzip libzip-dev libicu-dev \
    && docker-php-ext-install zip intl \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && apt-get clean && rm -rf /var/lib/apt/lists/*
# The full-suite coverage run (composer test-coverage) exceeds PHP's default
# 128M memory limit while aggregating pcov data for ~12k tests.
RUN echo 'memory_limit=1G' > /usr/local/etc/php/conf.d/zz-memory-limit.ini
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
