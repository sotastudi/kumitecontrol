#!/usr/bin/env bash
# Uso (desde web/): source scripts/dev-env.sh
# Pone PHP 8.4 de MAMP y Node 26 por delante en el PATH de esta shell.
# Prefer Homebrew ARM (/opt/homebrew); fall back to Intel (/usr/local).
unalias php composer 2>/dev/null
NODE26_BIN=""
if [[ -d /opt/homebrew/opt/node@26/bin ]]; then
  NODE26_BIN="/opt/homebrew/opt/node@26/bin"
elif [[ -d /usr/local/opt/node@26/bin ]]; then
  NODE26_BIN="/usr/local/opt/node@26/bin"
fi
export PATH="/Applications/MAMP/bin/php/php8.4.17/bin${NODE26_BIN:+:$NODE26_BIN}:$PATH"
if ! php -r 'exit(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 4 ? 0 : 1);'; then
    echo "ERROR: php no es 8.4 (es $(php -r 'echo PHP_VERSION;'))" >&2
fi
