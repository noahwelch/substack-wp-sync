#!/usr/bin/env zsh
# Build an installable plugin zip from committed content. The archive root has
# to stay substack-wp-sync: it must match the directory the plugin already
# occupies, or WordPress installs a second plugin beside the existing one.

#   ./bin/build-plugin-zip.sh            build from HEAD
#   ./bin/build-plugin-zip.sh v1.3.2     build from any ref
#   OUT_DIR=/tmp ./bin/build-plugin-zip.sh

set -euo pipefail

REF="${1:-HEAD}"
OUT_DIR="${OUT_DIR:-$HOME}"
PLUGIN_SLUG="substack-wp-sync"

# Everything else in the repo is development scaffolding. Nothing here reads
# vendor/ at runtime: the plugin loads its classes with require_once.
SHIP=(
    substack-sync.php
    uninstall.php
    LICENSE
    README.md
    admin
    includes
)

repo_root="${0:A:h:h}"
cd "$repo_root"

if ! git rev-parse --verify --quiet "$REF" >/dev/null; then
    print -u2 "error: no such ref: $REF"
    exit 1
fi

# From git, not the working tree, so an uncommitted experiment can never reach
# a zip that gets uploaded to a live site.
if [[ -n "$(git status --porcelain)" ]]; then
    print -u2 "note: working tree is dirty; building $REF, uncommitted changes excluded"
fi

stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT
mkdir -p "$stage/$PLUGIN_SLUG"

git archive --format=tar "$REF" -- "${SHIP[@]}" | tar -x -C "$stage/$PLUGIN_SLUG"

version="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\(.*\)$/\1/p' \
    "$stage/$PLUGIN_SLUG/substack-sync.php" | head -1 | tr -d '[:space:]')"

if [[ -z "$version" ]]; then
    print -u2 "error: could not read Version from the plugin header"
    exit 1
fi

zip_path="$OUT_DIR/$PLUGIN_SLUG-$version.zip"
rm -f "$zip_path"

# COPYFILE_DISABLE stops macOS writing ._ AppleDouble twins into the archive,
# which WordPress then shows as junk files inside the plugin directory.
( cd "$stage" && COPYFILE_DISABLE=1 zip -q -r -X "$zip_path" "$PLUGIN_SLUG" -x '*.DS_Store' )

print "built $zip_path ($(du -h "$zip_path" | cut -f1), from $(git rev-parse --short "$REF"))"
unzip -Z1 "$zip_path" | sed 's/^/  /'
