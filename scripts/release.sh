#!/bin/bash
# Package and publish a bridge release to S3.
#
# Usage:
#   ./scripts/release.sh v1.1.0
#   AWS_PROFILE=imagu ./scripts/release.sh v1.1.0
#
# Requires: aws-cli, git, zip, python3.

set -euo pipefail

export AWS_DEFAULT_REGION="${AWS_DEFAULT_REGION:-us-east-1}"

BUCKET="s3://imagu-binaries/bridge"
PLUGIN_KEY="bridge"
VERSION="${1:-}"

if [[ -z "$VERSION" ]]; then
  echo "Usage: $0 <version>  (e.g. v1.1.0)" >&2
  exit 1
fi

if [[ ! "$VERSION" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "ERROR: Version must be in format vX.Y.Z (e.g. v1.1.0)." >&2
  exit 1
fi

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# ── check dependencies ─────────────────────────────────────────────────────────
for cmd in aws git zip python3; do
  if ! command -v "$cmd" &>/dev/null; then
    echo "ERROR: '$cmd' not found in PATH." >&2
    exit 1
  fi
done

if ! aws sts get-caller-identity &>/dev/null; then
  echo "ERROR: AWS credentials not configured." >&2
  echo "" >&2
  echo "  Option 1 -- env vars:   AWS_ACCESS_KEY_ID=... AWS_SECRET_ACCESS_KEY=... $0 $VERSION" >&2
  echo "  Option 2 -- named profile: AWS_PROFILE=imagu $0 $VERSION" >&2
  exit 1
fi

# ── package the plugin into a zip ──────────────────────────────────────────────
# git archive ensures only tracked files are included and the archive is
# reproducible from any clean checkout. The --prefix puts everything under
# bridge/ so unzipping into a GLPI plugins folder works directly.
#
# Included: setup.php, hook.php, composer.json, index.html, LICENSE,
#           ajax/, front/, src/, css/, js/, public/, locales/, templates/
# Excluded automatically: .git/, .github/, scripts/, tools/, tests/,
#   README.md, CHANGELOG.md, CONTRIBUTING.md, .gitignore
# Tracked locales/*.mo are shipped as-is (no compile step needed).
echo "Packaging ${PLUGIN_KEY} ${VERSION}..."
mkdir -p "${ROOT}/dist"
ZIP_FILE="${ROOT}/dist/${PLUGIN_KEY}-${VERSION}.zip"

git -C "$ROOT" archive \
  --format=zip \
  --prefix="${PLUGIN_KEY}/" \
  HEAD \
  setup.php \
  hook.php \
  composer.json \
  index.html \
  LICENSE \
  ajax/ \
  front/ \
  src/ \
  css/ \
  js/ \
  public/ \
  locales/ \
  templates/ \
  -o "$ZIP_FILE"

SIZE=$(du -sh "$ZIP_FILE" | cut -f1)
echo "  Built:  ${ZIP_FILE}"
echo "  Size:   ${SIZE}"

# ── upload zip ─────────────────────────────────────────────────────────────────
echo "Uploading to ${BUCKET}/${VERSION}/..."
aws s3 cp "$ZIP_FILE" "${BUCKET}/${VERSION}/${PLUGIN_KEY}-${VERSION}.zip" \
  --content-type "application/zip"
echo "  ✓ ${PLUGIN_KEY}-${VERSION}.zip"

# ── update manifest ────────────────────────────────────────────────────────────
# Keeps a sorted version list and bumps "latest".
echo "Updating manifest..."
MANIFEST_TMP=$(mktemp /tmp/manifest.XXXXXX.json)
trap 'rm -f "$MANIFEST_TMP"' EXIT

EXISTING=$(aws s3 cp "${BUCKET}/manifest.json" - 2>/dev/null || echo '{}')
echo "$EXISTING" | python3 -c "
import sys, json

raw = sys.stdin.read().strip() or '{}'
data = json.loads(raw)
versions = data.get('versions', [])

if '$VERSION' not in versions:
    versions.append('$VERSION')

def semver_key(v):
    return [int(x) for x in v.lstrip('v').split('.')]

data['versions'] = sorted(versions, key=semver_key)
data['latest'] = '$VERSION'
print(json.dumps(data, indent=2))
" > "$MANIFEST_TMP"

aws s3 cp "$MANIFEST_TMP" "${BUCKET}/manifest.json" \
  --content-type "application/json" \
  --cache-control "no-cache, no-store"

# ── upload installer script ────────────────────────────────────────────────────
echo "Uploading install.sh..."
aws s3 cp "${ROOT}/scripts/install.sh" "${BUCKET}/install.sh" \
  --content-type "text/x-shellscript" \
  --cache-control "no-cache, no-store"

echo ""
echo "=== Released ${VERSION} ==="
echo ""
echo "Install commands:"
echo "  Latest:"
echo "    curl -fsSL https://imagu-binaries.s3.us-east-1.amazonaws.com/bridge/install.sh | bash"
echo ""
echo "  Pinned to ${VERSION}:"
echo "    curl -fsSL https://imagu-binaries.s3.us-east-1.amazonaws.com/bridge/install.sh | VERSION=${VERSION} bash"
echo ""
echo "  Custom plugins directory:"
echo "    curl -fsSL https://imagu-binaries.s3.us-east-1.amazonaws.com/bridge/install.sh | PLUGINS_DIR=/var/lib/glpi/plugins bash"
