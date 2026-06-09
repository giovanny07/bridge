#!/bin/bash
# Bump the bridge plugin version everywhere it appears, then commit and tag.
#
# Usage:
#   tools/bump-version.sh 1.1.1
#
# Updates the version in:
#   - setup.php       (PLUGIN_BRIDGE_VERSION)
#   - composer.json   ("version")
#   - README.md       (## Status — vX.Y.Z)
#
# Then creates a "Release vX.Y.Z" commit and a matching git tag. It does NOT
# push — it prints the push command, which is what triggers the release workflow.
#
# It deliberately leaves PLUGIN_BRIDGE_MIN_GLPI / PLUGIN_BRIDGE_MAX_GLPI and the
# README "Requirements" range untouched (those are GLPI compatibility, not the
# plugin version).

set -euo pipefail

VERSION="${1:-}"

if [[ -z "$VERSION" ]]; then
  echo "Usage: $0 X.Y.Z   (e.g. 1.1.1)" >&2
  exit 1
fi

if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "ERROR: Version must be bare semver X.Y.Z with no leading 'v' (e.g. 1.1.1)." >&2
  exit 1
fi

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

TAG="v${VERSION}"

# ── safety checks ───────────────────────────────────────────────────────────────
if [[ -n "$(git status --porcelain)" ]]; then
  echo "ERROR: Working tree is not clean. Commit or stash changes first." >&2
  git status --short >&2
  exit 1
fi

if git rev-parse -q --verify "refs/tags/${TAG}" >/dev/null; then
  echo "ERROR: Tag ${TAG} already exists." >&2
  exit 1
fi

BRANCH="$(git rev-parse --abbrev-ref HEAD)"
if [[ "$BRANCH" != "main" ]]; then
  echo "WARNING: You are on '${BRANCH}', not 'main'." >&2
fi

# ── current version (from setup.php, the source of truth) ───────────────────────
CURRENT="$(sed -n "s/.*PLUGIN_BRIDGE_VERSION', '\([0-9.]*\)'.*/\1/p" setup.php)"
echo "Bumping ${CURRENT:-?} -> ${VERSION}"

# ── rewrite the three locations (anchored patterns) ─────────────────────────────
sed -i "s/\(PLUGIN_BRIDGE_VERSION', '\)[0-9.]*'/\1${VERSION}'/" setup.php
sed -i "s/\(\"version\": \"\)[0-9.]*\(\"\)/\1${VERSION}\2/" composer.json
sed -i "s/^## Status — v[0-9.]*/## Status — v${VERSION}/" README.md

# ── verify each file actually changed ───────────────────────────────────────────
fail=0
grep -q "PLUGIN_BRIDGE_VERSION', '${VERSION}'" setup.php   || { echo "ERROR: setup.php not updated." >&2; fail=1; }
grep -q "\"version\": \"${VERSION}\"" composer.json        || { echo "ERROR: composer.json not updated." >&2; fail=1; }
grep -q "^## Status — v${VERSION}\$" README.md             || { echo "ERROR: README.md not updated." >&2; fail=1; }
if [[ $fail -ne 0 ]]; then
  echo "Reverting changes." >&2
  git checkout -- setup.php composer.json README.md
  exit 1
fi

# ── commit and tag ──────────────────────────────────────────────────────────────
git add setup.php composer.json README.md
git commit -m "Release ${TAG}"
git tag "${TAG}"

echo ""
echo "=== Bumped to ${TAG} and tagged ==="
echo ""
echo "Push to trigger the release workflow:"
echo "  git push origin ${BRANCH} --tags"
