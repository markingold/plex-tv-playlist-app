#!/usr/bin/env bash
set -euo pipefail

# -----------------------------
# Defaults (override via flags)
# -----------------------------
APP_NAME="plex-playlist"
COMPOSE_FILE="docker-compose.yml"
IMAGE="ghcr.io/markingold/plex-tv-playlist-app:latest"
PORT="${PORT:-8080}"

# DEFAULTS: full rebuild + recreate on plain ./run.sh
RESET_DATA="false"        # --reset
REBUILD_IMAGE="true"      # --build (compose build from local Dockerfile) [DEFAULT: true]
PULL_IMAGE="false"        # --pull  (pull latest prebuilt image)
FORCE_RECREATE="true"     # --force-recreate [DEFAULT: true]
CLEAR_OPCACHE="false"     # --clear-opcache
DEV_MODE="false"          # --dev (bind-mount source + disable opcache)
TAIL_LOGS="true"          # --no-tail
RUN_POPULATE="true"       # --no-populate
USE_COMPOSE="auto"        # auto|yes|no
USE_FALLBACK="false"      # internal: plain docker run
DOCKER_HOST_ARG=""        # --host tcp://x.x.x.x:2375
WAIT_SECS=25              # --wait-secs N

# Unraid/Synology toggle (affects fallback volumes if not running from repo dir)
UNRAID_MODE="false"       # --unraid
SYNO_MODE="false"         # --synology

# -----------------------------
# Helpers
# -----------------------------
log() { echo -e "\033[1;32m[+] $*\033[0m"; }
warn(){ echo -e "\033[1;33m[!] $*\033[0m"; }
err() { echo -e "\033[1;31m[✗] $*\033[0m" >&2; }
die() { err "$*"; exit 1; }

have() { command -v "$1" >/dev/null 2>&1; }

compose_bin() {
  if have docker && docker compose version >/dev/null 2>&1; then
    echo "docker compose"
  elif have docker-compose; then
    echo "docker-compose"
  else
    echo ""
  fi
}

usage() {
  cat <<EOF
Usage: $0 [options]

Defaults (no options): FULL REBUILD from current source + FORCE RECREATE.

Options:
  --reset             Remove ./database/*.db and ./logs/*.log before start
  --build             Rebuild the image via compose (build --pull --no-cache), then up
  --pull              Pull the prebuilt image tag (for fallback or compose pull)
  --force-recreate    Force container recreation (compose up --force-recreate)
  --clear-opcache     After start, reset PHP OpCache inside the container
  --dev               DEV mode: bind-mount repo and disable opcache (live code)
  --no-tail           Do not tail container logs after start
  --no-populate       Skip running populateShows.py after start
  --compose           Force using docker compose (default: auto-detect)
  --no-compose        Force plain 'docker run' (fallback mode)
  --unraid            Prefer plain 'docker run' and use local ./ paths (Unraid friendly)
  --synology          Prefer plain 'docker run' and use local ./ paths (Synology friendly)
  --host URL          Set DOCKER_HOST (e.g., tcp://192.168.1.10:2375)
  --wait-secs N       Wait N seconds for the app to come up (default: ${WAIT_SECS})
  --port N            Map host port N->80 (default: ${PORT})

Env:
  PORT: host bind port for the app UI (default: 8080)

Examples:
  # Default: rebuild from source + recreate:
  $0

  # Local edits, live-mount, no cache:
  $0 --no-compose --dev --no-populate

  # Using compose, rebuild image from local Dockerfile and recreate:
  $0 --compose --build --force-recreate

  # Using prebuilt image only, force-pull and recreate (fallback run):
  $0 --no-compose --pull --force-recreate
EOF
}

# -----------------------------
# Parse flags
# -----------------------------
while [[ $# -gt 0 ]]; do
  case "$1" in
    --reset)            RESET_DATA="true"; shift;;
    --build)            REBUILD_IMAGE="true"; shift;;
    --pull)             PULL_IMAGE="true"; shift;;
    --force-recreate)   FORCE_RECREATE="true"; shift;;
    --clear-opcache)    CLEAR_OPCACHE="true"; shift;;
    --dev)              DEV_MODE="true"; shift;;
    --no-tail)          TAIL_LOGS="false"; shift;;
    --no-populate)      RUN_POPULATE="false"; shift;;
    --compose)          USE_COMPOSE="yes"; shift;;
    --no-compose)       USE_COMPOSE="no"; shift;;
    --unraid)           UNRAID_MODE="true"; USE_COMPOSE="no"; shift;;
    --synology)         SYNO_MODE="true";  USE_COMPOSE="no"; shift;;
    --host)             DOCKER_HOST_ARG="$2"; shift 2;;
    --wait-secs)        WAIT_SECS="$2"; shift 2;;
    --port)             PORT="$2"; shift 2;;
    -h|--help)          usage; exit 0;;
    *)                  err "Unknown option: $1"; usage; exit 1;;
  esac
done

# Respect remote host if provided
if [[ -n "$DOCKER_HOST_ARG" ]]; then
  export DOCKER_HOST="$DOCKER_HOST_ARG"
  log "Using DOCKER_HOST=${DOCKER_HOST}"
fi

# Choose compose or fallback
COMPOSE_CMD="$(compose_bin)"
if [[ "$USE_COMPOSE" == "yes" ]]; then
  [[ -z "$COMPOSE_CMD" ]] && die "docker compose not found."
  USE_FALLBACK="false"
elif [[ "$USE_COMPOSE" == "no" ]]; then
  USE_FALLBACK="true"
else
  # auto
  if [[ -n "$COMPOSE_CMD" ]]; then
    USE_FALLBACK="false"
  else
    warn "docker compose not found, using plain 'docker run' fallback."
    USE_FALLBACK="true"
  fi
fi

# Ensure .env exists
if [[ ! -f ".env" ]]; then
  if [[ -f ".env.example" ]]; then
    log ".env not found — creating from .env.example"
    cp .env.example .env
  else
    warn ".env.example not found; creating a minimal .env"
    cat > .env <<EOF
PLEX_URL=
PLEX_TOKEN=
PLEX_VERIFY_SSL=false
PYTHON_EXEC=/usr/local/bin/python3
EOF
  fi
fi

# Ensure data dirs
mkdir -p database logs .docker

if [[ "$RESET_DATA" == "true" ]]; then
  log "Removing database and logs..."
  rm -f database/*.db logs/*.log || true
fi

# Stop old stack/containers
if [[ "$USE_FALLBACK" == "true" ]]; then
  log "Stopping and removing existing container (if running)..."
  docker rm -f "$APP_NAME" >/dev/null 2>&1 || true
else
  log "Bringing down compose stack (clean)…"
  ${COMPOSE_CMD} -f "$COMPOSE_FILE" down --remove-orphans || true
fi

# -----------------------------
# Start stack
# -----------------------------
if [[ "$USE_FALLBACK" == "false" ]]; then
  # ----- Compose path -----
  export PORT

  if [[ "$PULL_IMAGE" == "true" ]]; then
    log "Compose pull images…"
    ${COMPOSE_CMD} -f "$COMPOSE_FILE" pull
  fi

  if [[ "$REBUILD_IMAGE" == "true" ]]; then
    log "Compose build (no cache, pull base layers)…"
    ${COMPOSE_CMD} -f "$COMPOSE_FILE" build --pull --no-cache
  fi

  log "Compose up (detached)…"
  if [[ "$FORCE_RECREATE" == "true" || "$REBUILD_IMAGE" == "true" ]]; then
    ${COMPOSE_CMD} -f "$COMPOSE_FILE" up -d --force-recreate --remove-orphans
  else
    ${COMPOSE_CMD} -f "$COMPOSE_FILE" up -d
  fi

else
  # ----- Fallback: plain docker build/run -----
  IMAGE_TO_RUN="${IMAGE}"

  if [[ "$DEV_MODE" == "true" ]]; then
    log "DEV mode: bind-mounting repo and disabling PHP OpCache."
  fi

  if [[ "$REBUILD_IMAGE" == "true" && "$DEV_MODE" != "true" ]]; then
    # Build a local image from current source
    IMAGE_TO_RUN="${APP_NAME}:dev"
    log "Building local image '${IMAGE_TO_RUN}' from current source…"
    docker build --pull --no-cache -t "${IMAGE_TO_RUN}" .
  elif [[ "$PULL_IMAGE" == "true" && "$DEV_MODE" != "true" ]]; then
    log "Pulling latest prebuilt image ${IMAGE}…"
    docker pull "${IMAGE}" || warn "Pull failed; proceeding if image exists locally."
  else
    # Ensure image exists (pull if needed)
    if ! docker image inspect "${IMAGE_TO_RUN}" >/dev/null 2>&1; then
      log "Base image not present; pulling ${IMAGE}…"
      docker pull "${IMAGE}" || die "Unable to pull ${IMAGE}"
    fi
  fi

  # Volumes & run
  if [[ "$DEV_MODE" == "true" ]]; then
    # Drop a dev php.ini to disable opcache
    cat > .docker/php-dev.ini <<'INI'
opcache.enable=0
opcache.enable_cli=0
opcache.validate_timestamps=1
opcache.revalidate_freq=0
INI
    CODE_VOL="$(pwd):/var/www/html"
    PHP_INI_VOL="$(pwd)/.docker/php-dev.ini:/usr/local/etc/php/conf.d/zz-dev.ini"
    docker run -d --name "${APP_NAME}" \
      -p "${PORT}:80" \
      -v "${CODE_VOL}" \
      -v "${PHP_INI_VOL}" \
      --add-host "host.docker.internal:host-gateway" \
      -e APP_BUILD_TAG="$(date +%s)" \
      "${IMAGE_TO_RUN}"
  else
    DB_VOL="$(pwd)/database:/var/www/html/database"
    LOG_VOL="$(pwd)/logs:/var/www/html/logs"
    ENV_VOL="$(pwd)/.env:/var/www/html/.env"
    docker run -d --name "${APP_NAME}" \
      -p "${PORT}:80" \
      -v "${DB_VOL}" \
      -v "${LOG_VOL}" \
      -v "${ENV_VOL}" \
      --add-host "host.docker.internal:host-gateway" \
      -e APP_BUILD_TAG="$(date +%s)" \
      "${IMAGE_TO_RUN}"
  fi
fi

# -----------------------------
# Wait for app readiness
# -----------------------------
log "Waiting up to ${WAIT_SECS}s for app to become ready at http://localhost:${PORT} ..."
OK="false"
for ((i=1; i<=WAIT_SECS; i++)); do
  if curl -fs "http://localhost:${PORT}/" >/dev/null 2>&1; then
    OK="true"
    break
  fi
  sleep 1
done
if [[ "$OK" == "true" ]]; then
  log "App is live: http://localhost:${PORT}"
else
  warn "App did not respond within ${WAIT_SECS}s. Proceeding anyway."
fi

# -----------------------------
# Reset PHP OpCache (optional)
# -----------------------------
if [[ "$CLEAR_OPCACHE" == "true" ]]; then
  log "Resetting PHP OpCache in container (if available)…"
  # Best-effort: try the container named ${APP_NAME}
  docker exec "${APP_NAME}" sh -lc 'php -r "if (function_exists(\"opcache_reset\")) { opcache_reset(); echo \"opcache_reset ok\n\"; } else { echo \"opcache_reset not available\n\"; }"' || true
fi

# -----------------------------
# Run populateShows.py (optional)
# -----------------------------
if [[ "$RUN_POPULATE" == "true" ]]; then
  TS="$(date +%Y%m%d_%H%M%S)"
  HOST_LOG="logs/populate_${TS}.log"
  log "Running populateShows.py (logging to ${HOST_LOG})..."
  if docker exec "${APP_NAME}" /usr/local/bin/python3 /var/www/html/scripts/populateShows.py 2>&1 | tee "${HOST_LOG}"; then
    log "populateShows.py completed successfully."
  else
    warn "populateShows.py failed. Check ${HOST_LOG} for details."
  fi
fi

# -----------------------------
# Tail logs (optional)
# -----------------------------
if [[ "$TAIL_LOGS" == "true" ]]; then
  log "Tailing container logs (Ctrl+C to exit)…"
  docker logs -f "${APP_NAME}"
else
  log "Done. App: http://localhost:${PORT}"
fi
