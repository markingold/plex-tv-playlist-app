# Stage 1: build Python deps
FROM python:3.11-slim AS pydeps
WORKDIR /app
COPY scripts/requirements.txt /app/scripts/requirements.txt
RUN pip install --no-cache-dir -r /app/scripts/requirements.txt

# Stage 2: runtime (PHP + Apache)
# --- PHP web image ---
FROM php:8.2-apache

# System deps for sqlite (for pdo_sqlite build)
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
    libsqlite3-dev pkg-config curl ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# Build PDO SQLite (PDO core is already in PHP)
RUN docker-php-ext-install -j$(nproc) pdo_sqlite

# Copy app
WORKDIR /var/www/html
COPY . /var/www/html

# Entrypoint to fix perms / bootstrap .env
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]


# Harden Apache a little and set docroot
ENV APACHE_DOCUMENT_ROOT=/var/www/html
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/000-default.conf /etc/apache2/apache2.conf

# Copy app source
WORKDIR /var/www/html
COPY . /var/www/html

# Copy Python runtime from builder
COPY --from=pydeps /usr/local /usr/local

# Make writable dirs
RUN mkdir -p /var/www/html/database /var/www/html/logs \
    && chown -R www-data:www-data /var/www/html/database /var/www/html/logs \
    && chmod 2775 /var/www/html/database /var/www/html/logs

# Env defaults; users can override in compose
ENV PLEX_URL=""
ENV PLEX_TOKEN=""
ENV PLEX_VERIFY_SSL=false
ENV PYTHON_EXEC="/usr/local/bin/python3"

# Healthcheck: ensure index is reachable
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s \
    CMD curl -fsS http://localhost/ || exit 1

EXPOSE 80
