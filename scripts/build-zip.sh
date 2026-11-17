#!/bin/sh
# Builds dist/korkwand.zip (top-level folder korkwand/) according to .distignore. Requires rsync and zip.
set -eu
cd "$(dirname "$0")/.."
rm -rf dist
mkdir -p dist/korkwand
rsync -a --exclude-from=.distignore ./ dist/korkwand/
(cd dist && zip -qr korkwand.zip korkwand)
rm -rf dist/korkwand
echo "Created dist/korkwand.zip"
