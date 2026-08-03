#!/usr/bin/env bash
#
# Throwaway PrestaShop for testing the module self-update.
#
# WHY A SEPARATE CONTAINER: the everyday dev container bind-mounts
# modules/mdfcforps straight from the git working tree. A bind mount is a mount
# point, rename() on it fails with EBUSY, and the updater's swap is rename-based —
# so the update can never succeed there. Worse, if it somehow did, it would replace
# the working tree with the contents of the release ZIP and destroy uncommitted work.
#
# This script creates a container with a NORMAL directory for the module, copies the
# module in with `docker cp`, and serves test packages over plain HTTP.
#
# Usage:
#   tools/update-test-env.sh up [1.7.7.5|8]   create the shop and install the module
#   tools/update-test-env.sh package <version>  build + serve a test ZIP of that version
#   tools/update-test-env.sh fault <list|->     set/clear MDFCFORPS_UPDATE_FAULT
#   tools/update-test-env.sh state              show module version, phase, backups
#   tools/update-test-env.sh logs               tail the [MDF][update] PrestaShop logs
#   tools/update-test-env.sh reset              restore the shop to the base version
#   tools/update-test-env.sh down               destroy everything
#
# @author Marques de France
# @license AFL-3.0

set -euo pipefail

MODULE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
NAME=ps-update-test
DB_NAME=ps_update_test
DB_HOST=ps-mysql
DB_USER=prestashop
DB_PASS=prestashop
NETWORK=prestashop_ps_internal
HTTP_PORT=8099
ZIP_PORT=8081
ZIP_DIR=/tmp/mdfcforps-test-zips
ZIP_SERVER=mdf-zip-server
ADMIN_DIR=admin-dev
ADMIN_MAIL=admin@mdf.local
ADMIN_PASS=Mdf@admin2026
HUB_URL=http://host.docker.internal:3000

mysql_exec() {
  docker exec "$DB_HOST" mysql -u"$DB_USER" -p"$DB_PASS" -N -e "$1" 2>/dev/null
}

# The updater reads config through ModuleConfig, which resolves the current shop id
# and falls back to the global row, so writing the global row (id_shop NULL) is what
# a single-shop test install reads.
set_config() {
  local key="$1" value="$2"
  mysql_exec "USE $DB_NAME;
    DELETE FROM ps_configuration WHERE name='$key';
    INSERT INTO ps_configuration (name, value, date_add, date_upd)
    VALUES ('$key', '$value', NOW(), NOW());"
}

# Copy the module as it exists in the working tree — including files that are not
# committed yet — into $1.
#
# `git archive HEAD` is what the release workflow uses, but it only sees COMMITTED
# files, so during development it silently produces a package missing exactly the new
# code you are trying to test. `git stash create` does not help either: it excludes
# untracked files. So the file list is built from tracked + untracked-not-ignored, and
# the .gitattributes export-ignore entries are applied by hand to match the real ZIP.
export_worktree() {
  local dest="$1"
  mkdir -p "$dest"

  local excludes='^(tools/|tests/|\.github/|\.gitattributes|\.gitignore|phpunit\.xml\.dist|\.php_cs\.dist|vendor/)'

  git -C "$MODULE_ROOT" ls-files --cached --others --exclude-standard \
    | grep -Ev "$excludes" \
    | while IFS= read -r file; do
        [ -f "$MODULE_ROOT/$file" ] || continue
        mkdir -p "$dest/$(dirname "$file")"
        cp "$MODULE_ROOT/$file" "$dest/$file"
      done
}

cmd_up() {
  local variant="${1:-8}"
  local image="prestashop/prestashop:8-apache"
  [ "$variant" = "1.7.7.5" ] && image="prestashop/prestashop:1.7.7.5"

  echo "==> Recreating database $DB_NAME"
  mysql_exec "DROP DATABASE IF EXISTS $DB_NAME; CREATE DATABASE $DB_NAME;"

  echo "==> Removing any previous container"
  docker rm -f "$NAME" >/dev/null 2>&1 || true

  echo "==> Starting $image as $NAME (http://localhost:$HTTP_PORT/$ADMIN_DIR/)"
  # No bind mount for modules/ — that is the entire point of this container.
  # PS_DEV_MODE=1 is required: it is what makes _PS_MODE_DEV_ true, which in turn
  # enables MDFCFORPS_UPDATE_ALLOWED_HOSTS and MDFCFORPS_UPDATE_FAULT.
  docker run -d --name "$NAME" \
    --network "$NETWORK" \
    -p "$HTTP_PORT:80" \
    -e DB_SERVER="$DB_HOST" \
    -e DB_NAME="$DB_NAME" \
    -e DB_USER="$DB_USER" \
    -e DB_PASSWD="$DB_PASS" \
    -e PS_INSTALL_AUTO=1 \
    -e PS_ERASE_DB=0 \
    -e PS_DEV_MODE=1 \
    -e PS_DOMAIN="localhost:$HTTP_PORT" \
    -e PS_FOLDER_ADMIN="$ADMIN_DIR" \
    -e PS_FOLDER_INSTALL=install-dev \
    -e ADMIN_MAIL="$ADMIN_MAIL" \
    -e ADMIN_PASSWD="$ADMIN_PASS" \
    -e MDF_HUB_URL="$HUB_URL" \
    "$image" >/dev/null

  echo "==> Waiting for the PrestaShop installer (this takes a few minutes)…"
  for i in $(seq 1 120); do
    if docker exec "$NAME" test -f /var/www/html/config/settings.inc.php 2>/dev/null \
       || docker exec "$NAME" test -f /var/www/html/app/config/parameters.php 2>/dev/null; then
      sleep 5
      echo "    installed."
      break
    fi
    sleep 5
    [ "$i" = 120 ] && { echo "    TIMED OUT — check: docker logs $NAME"; exit 1; }
  done

  cmd_install_module
}

cmd_install_module() {
  echo "==> Copying the module in (docker cp, NOT a bind mount)"
  docker exec "$NAME" rm -rf /var/www/html/modules/mdfcforps
  # Copy from a clean git export so the container gets exactly what a merchant
  # would get, without .git, vendor/, tests/ or local scratch files.
  local tmp
  tmp="$(mktemp -d)"
  export_worktree "$tmp/mdfcforps"
  docker cp "$tmp/mdfcforps" "$NAME:/var/www/html/modules/"
  rm -rf "$tmp"
  docker exec "$NAME" chown -R www-data:www-data /var/www/html/modules/mdfcforps

  echo "==> Installing the module"
  docker exec "$NAME" php -d memory_limit=-1 /var/www/html/bin/console \
    prestashop:module install mdfcforps 2>&1 | tail -3 || \
    echo "    (install via console failed — install it from the back office instead)"

  echo "==> Allowing the local package host (dev mode only)"
  set_config MDFCFORPS_UPDATE_ALLOWED_HOSTS "$ZIP_SERVER,host.docker.internal"

  cat <<EOF

Ready.

  Back office : http://localhost:$HTTP_PORT/$ADMIN_DIR/
  Login       : $ADMIN_MAIL / $ADMIN_PASS

Next:
  1. tools/update-test-env.sh package 1.4.1
  2. Point the Hub at it and restart:
       PS_MODULE_LATEST_VERSION=1.4.1
       PS_MODULE_DOWNLOAD_URL=http://$ZIP_SERVER/mdfcforps.zip
       PS_MODULE_SHA256=<printed by the package command>
  3. Open Marques de France -> Dashboard and press "Update now".
  4. tools/update-test-env.sh state
EOF
}

cmd_package() {
  local version="${1:?usage: package <version>}"
  mkdir -p "$ZIP_DIR"

  echo "==> Building a $version package from the current working tree"
  local tmp
  tmp="$(mktemp -d)"
  export_worktree "$tmp/mdfcforps"

  # Force the requested version into the package so a test release can be produced
  # without committing a version bump.
  sed -i '' -E "s#(<version><!\[CDATA\[)[^]]*#\1$version#" "$tmp/mdfcforps/config.xml"
  sed -i '' -E "s#(public const VERSION = ')[^']*#\1$version#" "$tmp/mdfcforps/mdfcforps.php"
  sed -i '' -E "s#(\\\$this->version = ')[^']*#\1$version#" "$tmp/mdfcforps/mdfcforps.php"

  ( cd "$tmp" && zip -qr "$ZIP_DIR/mdfcforps.zip" mdfcforps )
  rm -rf "$tmp"

  local sha size
  sha="$(shasum -a 256 "$ZIP_DIR/mdfcforps.zip" | awk '{print $1}')"
  size="$(stat -f%z "$ZIP_DIR/mdfcforps.zip")"

  # Served from a container rather than a host process: a backgrounded
  # `python3 -m http.server` keeps the parent's stdout pipe open, so this script
  # would never return when its output is piped. A container also puts the package
  # on the same Docker network as the shop, so no host.docker.internal hop is needed.
  if [ -z "$(docker ps -q -f name="^$ZIP_SERVER$")" ]; then
    echo "==> Starting package server container ($ZIP_SERVER)"
    docker rm -f "$ZIP_SERVER" >/dev/null 2>&1 || true
    docker run -d --name "$ZIP_SERVER" \
      --network "$NETWORK" \
      -p "$ZIP_PORT:80" \
      -v "$ZIP_DIR:/usr/share/nginx/html:ro" \
      nginx:alpine >/dev/null
    sleep 2
  fi

  cat <<EOF

Package ready: $ZIP_DIR/mdfcforps.zip
  reachable from the shop at http://$ZIP_SERVER/mdfcforps.zip
  reachable from your Mac at http://localhost:$ZIP_PORT/mdfcforps.zip

Put these in mdf-connectors-hub/.env and restart the Hub:

  PS_MODULE_UPDATE_ENABLED=true
  PS_MODULE_LATEST_VERSION=$version
  PS_MODULE_DOWNLOAD_URL=http://$ZIP_SERVER/mdfcforps.zip
  PS_MODULE_SHA256=$sha
  PS_MODULE_SIZE=$size
EOF
}

cmd_fault() {
  local value="${1:?usage: fault <fail_rename2[,fail_restore]|->}"
  [ "$value" = "-" ] && value=""
  set_config MDFCFORPS_UPDATE_FAULT "$value"
  echo "MDFCFORPS_UPDATE_FAULT = '${value:-(cleared)}'"
}

cmd_state() {
  echo "== ps_module =="
  mysql_exec "SELECT name, version FROM $DB_NAME.ps_module WHERE name='mdfcforps';"
  echo "== config.xml on disk =="
  docker exec "$NAME" sh -c 'grep -o "<version>.*</version>" /var/www/html/modules/mdfcforps/config.xml' 2>/dev/null || echo "  MODULE DIRECTORY MISSING"
  echo "== update keys =="
  mysql_exec "SELECT name, LEFT(value,60) FROM $DB_NAME.ps_configuration WHERE name LIKE 'MDFCFORPS_UPDATE%';"
  echo "== decoded state =="
  mysql_exec "SELECT value FROM $DB_NAME.ps_configuration WHERE name='MDFCFORPS_UPDATE_STATE';" \
    | base64 -d 2>/dev/null | python3 -m json.tool 2>/dev/null || echo "  (empty)"
  echo "== leftover backups (should be none after success) =="
  docker exec "$NAME" sh -c 'ls -d /var/www/html/modules/mdfcforps_bak_* /var/www/html/modules/mdfcforps__new_* 2>/dev/null' || echo "  none"
}

cmd_logs() {
  mysql_exec "SELECT severity, message FROM $DB_NAME.ps_log WHERE message LIKE '%[MDF][update]%' ORDER BY id_log DESC LIMIT 40;"
}

cmd_reset() {
  echo "==> Restoring the module to the committed version"
  docker exec "$NAME" sh -c 'rm -rf /var/www/html/modules/mdfcforps_bak_* /var/www/html/modules/mdfcforps__new_*' || true
  set_config MDFCFORPS_UPDATE_STATE ''
  set_config MDFCFORPS_UPDATE_LAST_ERROR ''
  set_config MDFCFORPS_UPDATE_INFO ''
  set_config MDFCFORPS_UPDATE_CHECKED_AT '0'
  set_config MDFCFORPS_UPDATE_FAULT ''
  cmd_install_module
  local base
  base="$(php -r 'preg_match("#<version><!\[CDATA\[([^]]*)#", file_get_contents($argv[1]), $m); echo $m[1];' "$MODULE_ROOT/config.xml")"
  mysql_exec "UPDATE $DB_NAME.ps_module SET version='$base' WHERE name='mdfcforps';"
  docker exec "$NAME" sh -c 'rm -rf /var/www/html/var/cache/*' || true
  echo "Reset to $base."
}

cmd_down() {
  docker rm -f "$NAME" >/dev/null 2>&1 || true
  mysql_exec "DROP DATABASE IF EXISTS $DB_NAME;" || true
  docker rm -f "$ZIP_SERVER" >/dev/null 2>&1 || true
  echo "Removed container, database and package server."
}

case "${1:-}" in
  up)      shift; cmd_up "$@" ;;
  package) shift; cmd_package "$@" ;;
  fault)   shift; cmd_fault "$@" ;;
  state)   cmd_state ;;
  logs)    cmd_logs ;;
  reset)   cmd_reset ;;
  down)    cmd_down ;;
  *)       sed -n '3,26p' "${BASH_SOURCE[0]}"; exit 1 ;;
esac
