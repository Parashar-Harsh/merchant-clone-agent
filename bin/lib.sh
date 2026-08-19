#!/usr/bin/env bash
# Shared helpers + config for the merchant-clone-agent devstack toolkit.
# Sourced by every bin/*.sh script.

set -euo pipefail

# ---- config (override via env) ----
KCTX="${KCTX:-dev-serve}"                 # kubectl context
NS="${NS:-api}"                           # namespace the clone-api pod lives in
POD="${POD:-clone-api}"                   # in-cluster api pod name
LABEL="${LABEL:-harsh}"                   # devstack_label (non-base => exec allowed)
API_DEPLOY="${API_DEPLOY:-api-web-base}"  # base api deploy we clone image/SA/env from
REMOTE_DIR="/tmp/mcagent"                 # where php scripts land inside the pod
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"  # repo root
PHP_DIR="$HERE/php"
RUNS_DIR="$HERE/runs"

k() { kubectl --context "$KCTX" "$@"; }

# run a php script (already copied into the pod) via tinker, in a given MODE,
# with SRC_MID/TGT_MID/MODE env vars; print only @@...@@ marker lines.
run_php() {
  local script="$1" mode="${2:-test}" extra="${3:-}"
  k exec -n "$NS" "$POD" -c web -- sh -lc \
    "cd /app && SRC_MID='${SRC_MID:-}' TGT_MID='${TGT_MID:-}' MODE='$mode' $extra php -d opcache.enable_cli=0 artisan tinker $REMOTE_DIR/$script 2>&1" \
    2>&1 | grep -avE "Interned string buffer overflow|eprecated" | grep -aoE "@@[A-Za-z0-9_]+=.*@@" || true
}

# same, but return full (unfiltered-except-noise) output for debugging
run_php_raw() {
  local script="$1" mode="${2:-test}" extra="${3:-}"
  k exec -n "$NS" "$POD" -c web -- sh -lc \
    "cd /app && SRC_MID='${SRC_MID:-}' TGT_MID='${TGT_MID:-}' MODE='$mode' $extra php -d opcache.enable_cli=0 artisan tinker $REMOTE_DIR/$script 2>&1" \
    2>&1 | grep -avE "Interned string buffer overflow|eprecated"
}

# copy all local php/*.php into the pod
push_php() {
  k exec -n "$NS" "$POD" -c web -- sh -lc "mkdir -p $REMOTE_DIR" >/dev/null 2>&1 || true
  local f
  for f in "$PHP_DIR"/*.php; do
    k cp "$f" "$NS/$POD:$REMOTE_DIR/$(basename "$f")" -c web >/dev/null 2>&1
  done
}

log()  { printf '\033[1;34m[clone]\033[0m %s\n' "$*" >&2; }
warn() { printf '\033[1;33m[warn]\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31m[err]\033[0m %s\n' "$*" >&2; exit 1; }

# pull the JSON payload out of a single @@KEY=...@@ marker line
marker() { sed -E 's/^@@[A-Za-z0-9_]+=//; s/@@$//'; }
