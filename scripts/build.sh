#!/usr/bin/env bash
# Gera a pasta dist/ pronta para subir via FTP em public_html/.
#
# Uso:
#   ./scripts/build.sh
#
# Resultado:
#   dist/               -> conteúdo completo de public_html/
#   dist/api/            -> endpoint PHP (server/api/)
#   dist/libs/            -> PHPMailer (server/libs/)
#   (index.html, assets, favicon, robots.txt, sitemap.xml vêm do build do Svelte)

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="$ROOT_DIR/dist"

echo "==> Limpando dist/ anterior"
rm -rf "$DIST_DIR"
mkdir -p "$DIST_DIR"

echo "==> Buildando o frontend (Svelte + Vite)"
cd "$ROOT_DIR/site"
npm install
npm run build

echo "==> Copiando build do frontend para dist/"
cp -r "$ROOT_DIR/site/dist/." "$DIST_DIR/"

echo "==> Copiando backend PHP para dist/"
mkdir -p "$DIST_DIR/api" "$DIST_DIR/libs"
cp -r "$ROOT_DIR/server/api/." "$DIST_DIR/api/"
cp -r "$ROOT_DIR/server/libs/." "$DIST_DIR/libs/"

echo ""
echo "==> Pronto! Conteúdo de dist/ é o que vai para public_html/ via FTP."
echo "    Lembrete: .env e a pasta tmp/ NÃO fazem parte disso — eles já"
echo "    devem existir direto no servidor, um nível ACIMA de public_html/"
echo "    (ex: /home/equovali1/.env e /home/equovali1/tmp/)."
