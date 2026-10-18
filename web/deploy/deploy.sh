#!/usr/bin/env bash
# Deploy KumiteControl (Laravel web/) to production via rsync/SSH.
# Reads SSH_* and REMOTE_PATH from deploy/.env (not committed).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="$ROOT/deploy/.env"
DRY_RUN=0
SKIP_BUILD=0
SKIP_COMPOSER=0
DO_MIGRATE=0
DO_CLEANCACHE=0
STAGE=""

usage() {
  cat <<'EOF'
Usage: ./deploy/deploy.sh [--dry-run] [--skip-build] [--skip-composer] [--migrate] [--cleancache]

Reads SSH_* and REMOTE_PATH from deploy/.env (not committed).
By default: npm run build, composer --no-dev in a temp staging dir (local
vendor/ untouched), then rsync to REMOTE_PATH.
Protects remote .env and storage/ from overwrite/delete.
--cleancache deletes the cached config/routes/events/views files on the server
(needed after renaming/adding config files). It does not use artisan because
the host disables proc_open().
EOF
}

cleanup() {
  if [[ -n "$STAGE" && -d "$STAGE" ]]; then
    rm -rf "$STAGE"
  fi
}
trap cleanup EXIT

while [[ $# -gt 0 ]]; do
  case "$1" in
    --dry-run) DRY_RUN=1; shift ;;
    --skip-build) SKIP_BUILD=1; shift ;;
    --skip-composer) SKIP_COMPOSER=1; shift ;;
    --migrate) DO_MIGRATE=1; shift ;;
    --cleancache) DO_CLEANCACHE=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown option: $1" >&2; usage; exit 1 ;;
  esac
done

if [[ -f "$ENV_FILE" ]]; then
  set -a
  # shellcheck disable=SC1090
  source "$ENV_FILE"
  set +a
fi

if [[ ! -f "$ROOT/artisan" ]]; then
  echo "Missing $ROOT/artisan — run this from the Laravel web/ tree." >&2
  exit 1
fi

: "${SSH_HOST:?Set SSH_HOST in deploy/.env}"
: "${SSH_USER:?Set SSH_USER in deploy/.env}"
REMOTE_PATH="${REMOTE_PATH:-/web/kumite/}"
SSH_PORT="${SSH_PORT:-22}"

# Ensure trailing slash for rsync directory semantics
[[ "$REMOTE_PATH" == */ ]] || REMOTE_PATH="${REMOTE_PATH}/"

# PHP 8.4 (MAMP) + Node 26 — same paths as scripts/dev-env.sh
# Prefer Homebrew ARM (/opt/homebrew); fall back to Intel (/usr/local).
# (do not source that file: its `unalias` exits under set -e)
NODE26_BIN=""
if [[ -d /opt/homebrew/opt/node@26/bin ]]; then
  NODE26_BIN="/opt/homebrew/opt/node@26/bin"
elif [[ -d /usr/local/opt/node@26/bin ]]; then
  NODE26_BIN="/usr/local/opt/node@26/bin"
fi
export PATH="/Applications/MAMP/bin/php/php8.4.17/bin${NODE26_BIN:+:$NODE26_BIN}:$PATH"

if [[ "$SKIP_BUILD" -eq 0 ]]; then
  command -v npm >/dev/null || { echo "npm not found (¿Node 26 instalado?)" >&2; exit 1; }
fi
if [[ "$SKIP_COMPOSER" -eq 0 ]]; then
  command -v composer >/dev/null || { echo "composer not found" >&2; exit 1; }
fi

if [[ "$SKIP_BUILD" -eq 0 && "$DRY_RUN" -eq 0 ]]; then
  echo "==> npm run build"
  (cd "$ROOT" && npm run build)
elif [[ "$SKIP_BUILD" -eq 0 && "$DRY_RUN" -eq 1 ]]; then
  echo "==> npm run build (skipped in --dry-run)"
fi

DEPLOY_SRC="$ROOT"

if [[ "$SKIP_COMPOSER" -eq 0 && "$DRY_RUN" -eq 0 ]]; then
  # Build production vendor in a staging copy so local vendor/ (with --dev) stays intact.
  STAGE="$(mktemp -d "${TMPDIR:-/tmp}/kumitecontrol-deploy.XXXXXX")"
  echo "==> staging + composer install --no-dev"
  echo "    $STAGE"
  rsync -a \
    --exclude 'node_modules/' \
    --exclude '.git/' \
    --exclude 'vendor/' \
    --exclude 'tests/' \
    --exclude '.phpunit*' \
    --exclude '.DS_Store' \
    --exclude 'public/hot' \
    --exclude 'bootstrap/cache/config.php' \
    --exclude 'bootstrap/cache/routes-*.php' \
    --exclude 'bootstrap/cache/events.php' \
    --exclude 'storage/logs/*' \
    --exclude 'storage/framework/cache/*' \
    --exclude 'storage/framework/sessions/*' \
    --exclude 'storage/framework/views/*' \
    "$ROOT/" "$STAGE/"
  (cd "$STAGE" && composer install --no-dev --optimize-autoloader --no-interaction)
  DEPLOY_SRC="$STAGE"
elif [[ "$SKIP_COMPOSER" -eq 0 && "$DRY_RUN" -eq 1 ]]; then
  echo "==> composer install --no-dev (would run in staging; skipped in --dry-run)"
fi

RSYNC=(rsync -az --delete
  --exclude '.env'
  --exclude '.env.*'
  --exclude 'node_modules/'
  --exclude '.git/'
  --exclude 'tests/'
  --exclude '.phpunit*'
  --exclude '.DS_Store'
  --exclude 'public/hot'
  --exclude 'bootstrap/cache/config.php'
  --exclude 'bootstrap/cache/routes-*.php'
  --exclude 'bootstrap/cache/events.php'
  --exclude '.idea/'
  --exclude '.vscode/'
  --exclude '.cursor/'
  --exclude '.codex/'
  --exclude 'deploy/.env'
  --filter 'P .env'
  --filter 'P .env.*'
  --filter 'P storage/'
  --filter 'P storage/***'
  -e "ssh -p $SSH_PORT")

if [[ "$DRY_RUN" -eq 1 ]]; then
  RSYNC+=(--dry-run --itemize-changes)
fi

DEST="${SSH_USER}@${SSH_HOST}:${REMOTE_PATH}"
echo "==> deploy"
echo "    $DEPLOY_SRC/ -> $DEST"
"${RSYNC[@]}" "$DEPLOY_SRC/" "$DEST"

if [[ "$DRY_RUN" -eq 0 ]]; then
  echo "==> chmod public/build (Apache must read assets)"
  ssh -p "$SSH_PORT" "$SSH_USER@$SSH_HOST" \
    "chmod -R a+rX $(printf '%q' "${REMOTE_PATH%/}")/public/build $(printf '%q' "${REMOTE_PATH%/}")/public/img 2>/dev/null || true"
fi

if [[ "$DO_MIGRATE" -eq 1 ]]; then
  if [[ "$DRY_RUN" -eq 1 ]]; then
    echo "==> migrate (would run: php artisan migrate --force)"
  else
    echo "==> php artisan migrate --force"
    ssh -p "$SSH_PORT" "$SSH_USER@$SSH_HOST" \
      "cd $(printf '%q' "${REMOTE_PATH%/}") && COLUMNS=120 LINES=40 php artisan migrate --force"
  fi
fi

if [[ "$DO_CLEANCACHE" -eq 1 ]]; then
  # Equivalent to config:clear, route:clear, event:clear and view:clear. Done with
  # rm because the host disables proc_open(), which makes any artisan call fail.
  CLEAR_CMD="rm -f bootstrap/cache/config.php bootstrap/cache/routes-*.php bootstrap/cache/events.php && find storage/framework/views -type f -name '*.php' -delete"
  if [[ "$DRY_RUN" -eq 1 ]]; then
    echo "==> clean cache (would run: $CLEAR_CMD)"
  else
    echo "==> clean cache"
    ssh -p "$SSH_PORT" "$SSH_USER@$SSH_HOST" \
      "cd $(printf '%q' "${REMOTE_PATH%/}") && $CLEAR_CMD"
  fi
fi

echo "✅ Done."
