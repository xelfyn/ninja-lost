# Match the existing Debian/PHP preview ABI; copy only the Node runtime, not npm/yarn.
# Version-family tags are intentional for development; this is not a digest-pinned production image.
FROM node:24-bookworm-slim AS combat_node
FROM php:8.4-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends \
    git python3 unzip ca-certificates libicu-dev libzip-dev libpng-dev libonig-dev libstdc++6 \
    && docker-php-ext-install -j2 pdo_mysql intl mbstring bcmath gd zip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=combat_node /usr/local/bin/node /usr/local/bin/node
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN node -e "if (process.versions.node.split('.')[0] !== '24') process.exit(1)"
RUN useradd --create-home --uid 1000 --shell /bin/bash vscode \
    && mkdir -p /workspaces/ninja-lost && chown -R vscode:vscode /workspaces
USER vscode
WORKDIR /workspaces/ninja-lost
