#!/usr/bin/env bash
# Sobe o sistema num servidor PHP de teste e roda os testes de API.
set -euo pipefail
cd "$(dirname "$0")/.."
export INGRESSOS_TESTE=1
export INGRESSOS_DB="$(mktemp -d)/teste.db"
export SHALOM_TESTE=1
export SHALOM_DB="$(mktemp -d)/shalom.db"
echo "== Verificando sintaxe PHP =="
find ingressos api tests -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null
echo "ok"
php -S 127.0.0.1:8099 tests/router.php > /tmp/php-server.log 2>&1 &
SERVIDOR=$!
trap 'kill $SERVIDOR 2>/dev/null || true' EXIT
for i in $(seq 1 30); do curl -s -o /dev/null http://127.0.0.1:8099/painel && break; sleep 0.2; done
php tests/api.php
echo
echo "== Painel de células (admin.html) =="
php tests/celulas.php
if [ "${UI:-0}" = "1" ]; then
  node tests/ui.mjs
fi
if grep -iE "fatal|warning|deprecated|notice" /tmp/php-server.log; then echo "Avisos do PHP acima ↑"; exit 1; fi
