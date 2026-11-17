#!/bin/sh
# Erzeugt dist/korkwand.zip gemaess .distignore (benoetigt rsync und zip).
set -eu
cd "$(dirname "$0")/.."
rm -rf dist
mkdir -p dist/korkwand
rsync -a --exclude-from=.distignore ./ dist/korkwand/
(cd dist && zip -qr korkwand.zip korkwand)
rm -rf dist/korkwand
echo "dist/korkwand.zip erstellt"
