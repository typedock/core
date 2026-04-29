#!/usr/bin/env bash
# TypeDock — OWASP ZAP scan wrapper.
#
# Usage:
#   bin/security-scan.sh                    # baseline (passive only, ~2 min)
#   bin/security-scan.sh baseline           # same
#   bin/security-scan.sh full               # spider + active scan (public surface)
#   bin/security-scan.sh admin              # authenticated active scan against /admin/*
#
# Reports land on the host at ./storage/zap-reports/. The directory is chmod
# 777 because the ZAP container runs as uid 1000 (this is zaproxy's own
# documented workaround for bind-mount UID mismatches).
#
# `admin` mode prerequisites — set in docker.env, then `docker compose up -d`:
#   APP_DEBUG=true
#   SECURITY_SCAN_MODE=true
#   ZAP_ADMIN_USER=<email of an admin without 2FA>
#   ZAP_ADMIN_PASS=<password>
# Reset these BEFORE deploying anywhere real. Active scan WILL mutate content
# as the authenticated user — point at a throwaway DB only.
#
# ZAP exit codes: 0 = clean, 1 = FAIL-level findings, 2 = WARN only.

set -uo pipefail

cd "$(dirname "$0")/.."

# Preflight for `admin` mode: confirms the CsrfMiddleware bypass is active by
# POSTing to /admin/login without a token. If CSRF is still enforced we'd get
# 419; with the bypass active we get 200 (login page redisplayed because the
# bogus credentials fail authentication) or 302 to /admin.
preflight_admin() {
  if ! command -v curl >/dev/null 2>&1; then
    echo "  curl missing — skipping preflight, ZAP will fail visibly if bypass is off." >&2
    return
  fi

  local code
  code=$(curl -s -o /dev/null -w '%{http_code}' \
           -X POST \
           -d 'email=preflight@invalid&password=invalid' \
           http://localhost:8080/admin/login || echo 000)

  if [[ "$code" == "419" ]]; then
    cat >&2 <<'EOF'
==> Preflight FAILED: CSRF still enforced (HTTP 419 from /admin/login).
    Set the following in docker.env, then `docker compose up -d` to apply:
      APP_DEBUG=true
      SECURITY_SCAN_MODE=true
      ZAP_ADMIN_USER=<admin email, no 2FA>
      ZAP_ADMIN_PASS=<password>
    The bypass also requires the request to come from a private/loopback IP,
    which docker-compose-internal traffic satisfies automatically.
EOF
    exit 1
  fi

  echo "  preflight ok (HTTP $code)"
}

MODE="${1:-baseline}"
REPORT_DIR="storage/zap-reports"

mkdir -p "$REPORT_DIR"
chmod 777 "$REPORT_DIR"

echo "==> Bringing up app + nginx (idempotent)"
docker compose up -d app nginx

case "$MODE" in
  baseline)
    echo "==> Running ZAP baseline (passive) scan against http://nginx"
    docker compose --profile zap run --rm zap
    RC=$?
    ;;
  full)
    echo "==> Running ZAP Automation Framework (spider + active) scan"
    echo "    Config: docker/zap/full.yaml"
    docker compose --profile zap run --rm zap-full
    RC=$?
    ;;
  admin)
    echo "==> Preflight checks for authenticated admin scan"
    preflight_admin
    echo "==> Running authenticated admin scan via Automation Framework"
    echo "    Config: docker/zap/admin.yaml"
    docker compose --profile zap run --rm zap-admin
    RC=$?
    ;;
  *)
    echo "usage: $0 [baseline|full|admin]" >&2
    exit 2
    ;;
esac

echo
echo "==> Reports written to $REPORT_DIR/:"
ls -1 "$REPORT_DIR" 2>/dev/null || echo "  (none — scan likely failed before writing)"

case "$RC" in
  0) echo "==> ZAP: no findings at FAIL level." ;;
  1) echo "==> ZAP: FAIL-level findings present. Review the HTML report." ;;
  2) echo "==> ZAP: WARN-level findings only." ;;
  *) echo "==> ZAP: exited with $RC." ;;
esac

exit "$RC"
