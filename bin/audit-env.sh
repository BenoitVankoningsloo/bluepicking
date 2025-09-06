#!/usr/bin/env bash
set -euo pipefail

echo "== AUDIT DES VARIABLES DATABASE_URL =="

# 1. Fichiers .env*
echo "-- Contenu des .env* (si présents) --"
for f in .env .env.local .env.prod .env.prod.local .env.local.php; do
    if [ -f "$f" ]; then
        echo ">>> $f"
        if [[ "$f" == *.php ]]; then
            php -r "var_export(include '$f');"
        else
            grep -n DATABASE_URL "$f" || echo "  (pas de DATABASE_URL)"
        fi
        echo
    fi
done

# 2. Variable d'environnement dans le shell courant
echo "-- Variable d'environnement dans ce shell --"
echo "\$DATABASE_URL=$DATABASE_URL" 2>/dev/null || echo "DATABASE_URL non défini"
echo

# 3. Variable vue par Symfony
echo "-- Valeur active (Symfony parameters) --"
php bin/console debug:container --env=prod --parameters | grep DATABASE_URL || echo "Pas trouvé"

echo "== FIN AUDIT =="

