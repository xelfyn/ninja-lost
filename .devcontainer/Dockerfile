FROM php:8.4-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends \
    git python3 unzip ca-certificates libicu-dev libzip-dev libpng-dev libonig-dev \
    && docker-php-ext-install -j2 pdo_mysql intl mbstring bcmath gd zip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN useradd --create-home --uid 1000 --shell /bin/bash vscode \
    && mkdir -p /workspaces/ninja-lost && chown -R vscode:vscode /workspaces
USER vscode
WORKDIR /workspaces/ninja-lost
