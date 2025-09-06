#!/usr/bin/env bash
set -euo pipefail
ROOT="${1:-/var/www/hello-crud}"
cd "$ROOT"

echo "== Cleanup =="
rm -rf var/cache/* var/log/*
rm -rf public/bundles/* || true
rm -rf node_modules || true
rm -rf migrations/*.php || true

# Pas de suppression DB ici pour sécurité
php bin/console cache:clear --env=prod

echo "OK"

