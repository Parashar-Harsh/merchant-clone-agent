#!/usr/bin/env bash
# merchant-clone-agent — end-to-end clone of a source MID onto a target MID on devstack.
#
# Usage:
#   bin/clone.sh --source <MID> [--target <MID>] [--mint] [--modes "test live"] [--phase all|export|apply|verify]
#
#   --source   source merchant id (required)
#   --target   existing target merchant id (omit + pass --mint to create one)
#   --mint     create + activate a fresh target merchant first
#   --modes    space-separated modes to process (default: "test live")  <-- dual-mode by default
#   --phase    which phase(s) to run (default: all = export -> [mint] -> apply -> verify)
#
# Everything runs inside an in-cluster api pod (see bin/ensure-pod.sh) via php artisan tinker.
# Output + a JSON report land in runs/.

source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

SRC="" ; TGT="" ; MINT=0 ; MODES="test live" ; PHASE="all"
while [ $# -gt 0 ]; do
  case "$1" in
    --source) SRC="$2"; shift 2;;
    --target) TGT="$2"; shift 2;;
    --mint)   MINT=1; shift;;
    --modes)  MODES="$2"; shift 2;;
    --phase)  PHASE="$2"; shift 2;;
    *) die "unknown arg: $1";;
  esac
done
[ -n "$SRC" ] || die "--source <MID> is required"
export SRC_MID="$SRC"

mkdir -p "$RUNS_DIR"
STAMP="$(date +%Y%m%d-%H%M%S)"

log "ensuring in-cluster api pod ..."
"$HERE/bin/ensure-pod.sh"
log "pushing php phase scripts into pod ..."
push_php

REPORT="$RUNS_DIR/clone-$SRC-$STAMP.txt"
echo "merchant-clone-agent run $STAMP  source=$SRC  modes=[$MODES]" | tee "$REPORT"

# ---- EXPORT (per mode) ----
if [ "$PHASE" = "all" ] || [ "$PHASE" = "export" ]; then
  for m in $MODES; do
    log "EXPORT source in mode=$m"
    run_php export.php "$m" | tee -a "$REPORT"
  done
fi

# ---- MINT (optional; test mode) ----
if [ "$MINT" = "1" ]; then
  log "MINT target merchant ..."
  OUT="$(run_php mint_target.php test)"
  echo "$OUT" | tee -a "$REPORT"
  TGT="$(echo "$OUT" | grep -oE '@@CREATED_MID=.*@@' | marker | python3 -c 'import sys,json;print(json.load(sys.stdin)["mid"])' 2>/dev/null || true)"
  [ -n "$TGT" ] || die "mint failed — no target MID produced (see report)"
  log "minted target: $TGT"
fi
[ -n "$TGT" ] || die "no --target and no --mint; nothing to clone into"
export TGT_MID="$TGT"
echo "target=$TGT" | tee -a "$REPORT"

# ---- APPLY (per mode) ----
if [ "$PHASE" = "all" ] || [ "$PHASE" = "apply" ]; then
  for m in $MODES; do
    log "APPLY clone in mode=$m"
    run_php apply.php "$m" | tee -a "$REPORT"
  done
fi

# ---- VERIFY (per mode) ----
if [ "$PHASE" = "all" ] || [ "$PHASE" = "verify" ]; then
  for m in $MODES; do
    log "VERIFY parity in mode=$m"
    run_php verify.php "$m" | tee -a "$REPORT"
  done
fi

log "done. report: $REPORT"
echo "$REPORT"
