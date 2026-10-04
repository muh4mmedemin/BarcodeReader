#!/usr/bin/env bash
# Backend (API) ve frontend'i iki AYRI sunucuda başlatır — gerçek dağıtımdaki gibi bağımsız.
#   API:      http://localhost:8000
#   Frontend: http://localhost:5173
#   HTTPS:    https://<ip>:5443  (telefon kamerası için; node ve openssl varsa)
set -euo pipefail
cd "$(dirname "$0")/.."

API_PORT="${API_PORT:-8000}"
WEB_PORT="${WEB_PORT:-5173}"
HTTPS_PORT="${HTTPS_PORT:-5443}"
TLS_DIR=backend/storage/tls

php backend/bin/migrate.php --seed

php -S "0.0.0.0:${API_PORT}" -t backend/public backend/public/index.php &
API_PID=$!
php -S "0.0.0.0:${WEB_PORT}" -t frontend &
WEB_PID=$!
PIDS="$API_PID $WEB_PID"

if command -v node >/dev/null && command -v openssl >/dev/null; then
  if [[ ! -f $TLS_DIR/cert.pem ]]; then
    mkdir -p "$TLS_DIR"
    openssl req -x509 -newkey rsa:2048 -nodes -days 3650 -subj "/CN=BarcodeReader" \
      -keyout "$TLS_DIR/key.pem" -out "$TLS_DIR/cert.pem" 2>/dev/null
  fi
  node scripts/https-proxy.mjs "$TLS_DIR/cert.pem" "$TLS_DIR/key.pem" "$HTTPS_PORT" "$API_PORT" "$WEB_PORT" &
  PIDS="$PIDS $!"
  HTTPS_INFO="https://$(hostname -I 2>/dev/null | awk '{print $1}'):${HTTPS_PORT}/  (telefon kamerası)"
else
  HTTPS_INFO="kapalı (node ve openssl gerekli)"
fi

trap 'kill $PIDS 2>/dev/null' EXIT INT TERM

echo "API:       http://localhost:${API_PORT}/api/v1/health"
echo "Arayüz:    http://localhost:${WEB_PORT}/"
echo "HTTPS:     ${HTTPS_INFO}"
wait
