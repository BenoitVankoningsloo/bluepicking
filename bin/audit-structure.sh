#!/usr/bin/env bash
set -euo pipefail
cd "${1:-/var/www/hello-crud}"

echo "== AUDIT STRUCTURE =="
echo
echo "[1] Entités hors src/Entity (dangereux) :"
grep -R --line-number -E "#\[ORM\\\Entity" src | grep -v "src/Entity/" || echo "OK"
echo

echo "[2] DTO référencés :"
grep -R --line-number "namespace App\\\Dto" src || echo "Aucun DTO"
grep -R --line-number "App\\\Dto" src templates || true
echo

echo "[3] Domain contient-il des entités ?"
if [ -d src/Domain ]; then
  grep -R --line-number -E "#\[ORM\\\Entity" src/Domain || echo "Pas d'entités dans Domain"
else
  echo "Pas de dossier src/Domain"
fi
echo

echo "[4] Authenticators actifs (container tag security.authenticator) :"
php bin/console debug:container --tag=security.authenticator --env=prod || true
echo

echo "[5] Résidus 'contact_index' / 'contactentry' :"
grep -R --line-number -i "contact_index" src templates || echo "OK"
grep -R --line-number -i "contactentry" src templates || echo "OK"
echo

echo "[6] Conflits de casse sur User* :"
find src/Entity -iname 'user*.php' -ls || true
echo

echo "== FIN AUDIT =="

