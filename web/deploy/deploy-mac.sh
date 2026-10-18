#!/usr/bin/env bash
# Deploy entrypoint for this Apple Silicon Mac (MAMP PHP 8.4 + Homebrew Node 26).
# Sets PATH, checks arm64 / rolldown binding, then runs deploy/deploy.sh.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"

PHP84_BIN="/Applications/MAMP/bin/php/php8.4.17/bin"
NODE26_BIN="/opt/homebrew/opt/node@26/bin"
MAMP_PHP_DIR="/Applications/MAMP/bin/php"

if [[ ! -d "$PHP84_BIN" ]]; then
  echo "Missing MAMP PHP 8.4.17 at $PHP84_BIN" >&2
  exit 1
fi
if [[ ! -d "$NODE26_BIN" ]]; then
  echo "Missing Homebrew node@26 at $NODE26_BIN (brew install node@26)" >&2
  exit 1
fi

export PATH="$PHP84_BIN:$NODE26_BIN:$MAMP_PHP_DIR:$PATH"

if [[ "$(uname -m)" != "arm64" ]]; then
  echo "Este script es para Apple Silicon (arm64); uname -m=$(uname -m)" >&2
  exit 1
fi

NODE_ARCH="$(node -p 'process.arch')"
if [[ "$NODE_ARCH" != "arm64" ]]; then
  echo "Node no es arm64 (process.arch=$NODE_ARCH). Usa $NODE26_BIN." >&2
  exit 1
fi

need_reinstall=0
BINDING="$ROOT/node_modules/@rolldown/binding-darwin-arm64"
if [[ ! -d "$BINDING" ]]; then
  echo "==> falta binding-darwin-arm64"
  need_reinstall=1
fi
# Dropbox / instalaciones antiguas dejan un árbol .pnpm; el shim de vite apunta ahí y falla.
if [[ -d "$ROOT/node_modules/.pnpm" ]]; then
  echo "==> node_modules/.pnpm detectado (árbol híbrido)"
  need_reinstall=1
fi
if [[ ! -f "$ROOT/node_modules/vite/bin/vite.js" ]]; then
  echo "==> vite.js no encontrado en node_modules/vite"
  need_reinstall=1
fi

if [[ "$need_reinstall" -eq 1 ]]; then
  echo "==> reinstalando node_modules con npm ci"
  rm -rf "$ROOT/node_modules"
  (cd "$ROOT" && npm ci)
fi

exec "$ROOT/deploy/deploy.sh" "$@"
