#!/usr/bin/env bash
# Ensure an in-cluster api runtime pod (clone-api) exists under a NON-base label
# so exec is allowed AND DB writes clear the workload guardrail.
# Idempotent: reuses the pod if already Running.

source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

if k get pod "$POD" -n "$NS" --no-headers 2>/dev/null | grep -q Running; then
  log "pod $POD already running"
  exit 0
fi

log "reading image / serviceAccount / envFrom from $API_DEPLOY ..."
IMAGE="$(k get deploy "$API_DEPLOY" -n "$NS" -o jsonpath='{.spec.template.spec.containers[0].image}')"
SA="$(k get deploy "$API_DEPLOY" -n "$NS" -o jsonpath='{.spec.template.spec.serviceAccountName}')"
[ -n "$IMAGE" ] || die "could not read api image from $API_DEPLOY"
log "image=$IMAGE sa=$SA"

# collect the secretRef names the base deploy mounts (api-secrets-v1, db secrets, aws)
mapfile -t SECRETS < <(k get deploy "$API_DEPLOY" -n "$NS" -o json \
  | python3 -c 'import sys,json;[print(r["secretRef"]["name"]) for r in json.load(sys.stdin)["spec"]["template"]["spec"]["containers"][0].get("envFrom",[]) if "secretRef" in r]')
[ "${#SECRETS[@]}" -gt 0 ] || die "no envFrom secretRefs found on $API_DEPLOY"

ENVFROM=""
for s in "${SECRETS[@]}"; do
  ENVFROM="$ENVFROM
    - secretRef:
        name: $s"
done

TMP="$(mktemp -t clone-api.XXXX.yaml)"
cat > "$TMP" <<YAML
apiVersion: v1
kind: Pod
metadata:
  name: $POD
  namespace: $NS
  labels:
    devstack_label: $LABEL
    app: clone-api
spec:
  serviceAccountName: $SA
  restartPolicy: Never
  containers:
  - name: web
    image: $IMAGE
    command: ["sh","-c","sleep 14400"]
    envFrom:$ENVFROM
    resources:
      requests: {cpu: "200m", memory: "512Mi"}
      limits: {cpu: "1", memory: "2Gi"}
YAML

log "creating pod $POD (label devstack_label=$LABEL) ..."
k apply -f "$TMP" >&2
rm -f "$TMP"
k wait --for=condition=Ready "pod/$POD" -n "$NS" --timeout=180s >&2
log "pod ready"
