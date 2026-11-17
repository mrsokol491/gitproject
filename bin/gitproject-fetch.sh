#!/usr/bin/env bash
# gitproject-fetch.sh — лёгкий fetch хаба из GitVerse (ветка + теги), без изменений дерева.
set -euo pipefail
HUB="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd -- "$HUB"
git fetch gitverse master --tags --force >&2 || { echo "✗ fetch не удался" >&2; exit 1; }
echo "✓ Fetch завершён" >&2