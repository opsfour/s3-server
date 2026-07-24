FROM php:8.4-cli-bookworm@sha256:138a210978c7767ef2a26f499c413fe6de1c13233c9a5068139565c81191b1ac

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        libcurl4-openssl-dev \
        libonig-dev \
        libpq-dev \
        libsqlite3-dev \
        libxml2-dev \
        procps \
    && docker-php-ext-install -j"$(nproc)" \
        curl \
        mbstring \
        pcntl \
        pgsql \
        pdo_pgsql \
        pdo_sqlite \
        simplexml \
        sockets \
    && rm -rf /var/lib/apt/lists/*

RUN useradd --create-home --uid 10001 soak

USER soak
WORKDIR /app
