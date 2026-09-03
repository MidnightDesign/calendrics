FROM node:24-slim AS node-builder
# Install acorn (JS AST parser) globally so we can copy just the package
RUN npm install -g acorn@8

FROM php:8.4-cli

# Copy Node.js runtime for the test262 transpiler
COPY --from=node:24-slim /usr/local/bin/node /usr/local/bin/node
# Copy acorn package (used by tools/transpile-test262.mjs). It lands in the root
# node_modules rather than the global one: Node's ESM resolver ignores the global
# directory and instead walks node_modules up from the importing file, and /app is
# a bind mount, so /node_modules is the nearest directory the image can populate.
COPY --from=node-builder /usr/local/lib/node_modules/acorn /node_modules/acorn

RUN apt-get update && apt-get install -y git rsync unzip libzip-dev libicu-dev \
    && docker-php-ext-install zip intl \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && apt-get clean && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# php:8.4-cli ships PHP's 128M default, which the coverage step of `composer check`
# exhausts. It belongs in the image rather than in per-script flags: CI gets the
# same headroom from setup-php's memory_limit=-1.
RUN echo 'memory_limit=512M' > /usr/local/etc/php/conf.d/memory-limit.ini

# Run as the developer's own uid/gid. /app is a bind mount, so anything the
# container writes into it — transpiled test262 scripts, coverage output, vendor/ —
# keeps host ownership, and git no longer sees the checkout as owned by a stranger.
ARG UID=1000
ARG GID=1000
RUN if ! getent group "$GID" > /dev/null; then groupadd -g "$GID" app; fi \
    && useradd -u "$UID" -g "$GID" -m -s /bin/bash app \
    && mkdir -p /home/app/.composer/cache \
    && chown -R "$UID:$GID" /home/app
ENV COMPOSER_HOME=/home/app/.composer
USER app
