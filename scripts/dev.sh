#!/usr/bin/env bash
# Backend (API) ve frontend'i iki AYRI sunucuda başlatır — gerçek dağıtımdaki gibi bağımsız.
#   API:      http://localhost:8000
#   Frontend: http://localhost:5173
set -euo pipefail
cd "$(dirname "$0")/.."

API_PORT="${API_PORT:-8000}"
WEB_PORT="${WEB_PORT:-5173}"

php backend/bin/migrate.php --seed

php -S "0.0.0.0:${API_PORT}" -t backend/public backend/public/index.php &
API_PID=$!
php -S "0.0.0.0:${WEB_PORT}" -t frontend &
WEB_PID=$!

trap 'kill $API_PID $WEB_PID 2>/dev/null' EXIT INT TERM

echo "API:       http://localhost:${API_PORT}/api/v1/health"
echo "Temsilci:  http://localhost:${WEB_PORT}/rep/"
echo "Üretim:    http://localhost:${WEB_PORT}/production/"
wait
