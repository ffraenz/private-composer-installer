
FROM composer:1.8 AS composer

FROM php:7.3-fpm

# Install dependencies
RUN apt-get update \
    ; \
    apt-get install -y --no-install-recommends \
        git \
        subversion \
        unzip \
    ; \
    apt-get autoremove -y; \
    apt-get clean; \
    rm -rf /var/lib/apt/lists/*

# Copy the composer binary from the composer image
COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /srv
