#!/bin/sh
# Erzeugt dist/nz-pinnwand.zip gemaess .distignore (benoetigt rsync und zip).
set -eu
cd "$(dirname "$0")/.."
rm -rf dist
mkdir -p dist/nz-pinnwand
rsync -a --exclude-from=.distignore ./ dist/nz-pinnwand/
(cd dist && zip -qr nz-pinnwand.zip nz-pinnwand)
rm -rf dist/nz-pinnwand
echo "dist/nz-pinnwand.zip erstellt"
