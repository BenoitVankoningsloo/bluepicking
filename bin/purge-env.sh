#!/usr/bin/env bash
set -euo pipefail

echo "== PURGE DATABASE_URL =="

# Vérifier si DATABASE_URL est défini dans ce shell
if printenv DATABASE_URL >/dev/null 2>&1; then
  echo "[INFO] DATABASE_URL trouvé dans ce shell :"
  echo "       $DATABASE_URL"
  echo "[ACTION] unset DATABASE_URL"
  unset DATABASE_URL
else
  echo "[OK] Pas de DATABASE_URL dans ce shell"
fi

echo
echo "--> Vérification côté Symfony (parameters):"
php bin/console debug:container --env=prod --parameters | grep DATABASE_URL || echo "Pas trouvé dans Symfony"

echo "== FIN PURGE =="

