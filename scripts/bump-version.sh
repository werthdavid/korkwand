#!/bin/sh
# Sets the plugin version everywhere it has to be written down:
#   korkwand.php header (KORKWAND_VERSION is read from it), readme.txt Stable tag,
#   package.json / package-lock.json.
# Usage: npm run bump 0.3.0   (or: sh scripts/bump-version.sh 0.3.0)
set -eu
cd "$(dirname "$0")/.."

NEW="${1:-}"
if ! printf '%s' "$NEW" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$'; then
	echo "Usage: npm run bump <major.minor.patch>" >&2
	exit 1
fi

# Portable in-place edit (BSD and GNU sed differ on -i).
replace() {
	sed "$1" "$2" > "$2.tmp" && mv "$2.tmp" "$2"
}

replace "s/^\( \* Version:[[:space:]]*\).*/\1$NEW/" korkwand.php
replace "s/^\(Stable tag:[[:space:]]*\).*/\1$NEW/" readme.txt
npm version "$NEW" --no-git-tag-version --allow-same-version >/dev/null

echo "Version set to $NEW (korkwand.php, readme.txt, package.json)."
if ! grep -q "^= $NEW =" readme.txt; then
	echo "Reminder: add a '= $NEW =' entry to the changelog in readme.txt." >&2
fi
