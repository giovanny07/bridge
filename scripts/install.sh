#!/bin/bash
# bridge GLPI plugin installer / uninstaller
#
# One-liner install (always fetches latest):
#   curl -fsSL https://imagu-binaries.s3.us-east-1.amazonaws.com/bridge/install.sh | bash
#
# Pin a specific version:
#   curl -fsSL ... | VERSION=v1.1.0 bash
#
# Override plugins directory (for non-standard installations):
#   curl -fsSL ... | PLUGINS_DIR=/var/lib/glpi/plugins bash
#
# Uninstall:
#   curl -fsSL ... | UNINSTALL=true bash
#   curl -fsSL ... | UNINSTALL=true PLUGINS_DIR=/var/lib/glpi/plugins bash

set -euo pipefail

BASE_URL="https://imagu-binaries.s3.us-east-1.amazonaws.com/bridge"
MANIFEST_URL="${BASE_URL}/manifest.json"
PLUGIN_KEY="bridge"

# Terminal colors -- only when stdout is attached to a terminal (not a pipe).
if [[ -t 1 ]]; then
  RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BOLD='\033[1m'; NC='\033[0m'
else
  RED=''; GREEN=''; YELLOW=''; BOLD=''; NC=''
fi

err() { echo -e "${RED}ERROR: $*${NC}" >&2; }
ok()  { echo -e "${GREEN}$*${NC}"; }
warn(){ echo -e "${YELLOW}$*${NC}"; }

# ── detect GLPI plugins directories ───────────────────────────────────────────
# Returns a newline-separated list of candidate paths (may be empty).
find_candidates() {
  local -A seen=()
  local candidates=()

  # 1. Well-known fixed paths -- covers package-manager and standard manual installs.
  local known=(
    "/var/lib/glpi/plugins"
    "/var/www/html/glpi/plugins"
    "/var/www/html/glpi-11/plugins"
    "/var/www/html/glpi-10/plugins"
    "/var/www/glpi/plugins"
    "/usr/share/glpi/plugins"
    "/opt/glpi/plugins"
    "/srv/glpi/plugins"
    "/app/glpi/plugins"
  )
  for p in "${known[@]}"; do
    if [[ -d "$p" && -z "${seen[$p]:-}" ]]; then
      seen[$p]=1
      candidates+=("$p")
    fi
  done

  # 2. Scan web roots: find any "plugins" directory whose parent contains
  #    front/central.php -- that is a reliable GLPI root marker.
  while IFS= read -r -d '' plugins_dir; do
    local glpi_root
    glpi_root=$(dirname "$plugins_dir")
    if [[ -f "${glpi_root}/front/central.php" && -z "${seen[$plugins_dir]:-}" ]]; then
      seen[$plugins_dir]=1
      candidates+=("$plugins_dir")
    fi
  done < <(find /var/www /var/lib /opt /usr/share /srv /app -maxdepth 7 \
             -type d -name "plugins" -print0 2>/dev/null)

  printf '%s\n' "${candidates[@]:-}"
}

# ── resolve plugins directory ──────────────────────────────────────────────────
# Sets CHOSEN_PLUGINS_DIR or exits with a helpful message.
resolve_plugins_dir() {
  if [[ -n "${PLUGINS_DIR:-}" ]]; then
    if [[ ! -d "$PLUGINS_DIR" ]]; then
      err "PLUGINS_DIR='${PLUGINS_DIR}' does not exist."
      exit 1
    fi
    CHOSEN_PLUGINS_DIR="$PLUGINS_DIR"
    echo "Using PLUGINS_DIR: ${CHOSEN_PLUGINS_DIR}"
    return
  fi

  echo "Searching for GLPI plugins directory..."
  mapfile -t CANDIDATES < <(find_candidates)

  case "${#CANDIDATES[@]}" in
    0)
      err "No GLPI plugins directory found automatically."
      echo "" >&2
      echo "Re-run with PLUGINS_DIR set to your GLPI plugins folder:" >&2
      echo "  curl -fsSL ${BASE_URL}/install.sh | PLUGINS_DIR=/var/lib/glpi/plugins bash" >&2
      exit 1
      ;;
    1)
      CHOSEN_PLUGINS_DIR="${CANDIDATES[0]}"
      ok "Found: ${CHOSEN_PLUGINS_DIR}"
      ;;
    *)
      warn "Multiple GLPI installations found:"
      for c in "${CANDIDATES[@]}"; do
        echo "    ${c}"
      done
      echo "" >&2
      echo "Re-run with PLUGINS_DIR to select one, for example:" >&2
      for c in "${CANDIDATES[@]}"; do
        echo "  curl -fsSL ${BASE_URL}/install.sh | PLUGINS_DIR=${c} bash" >&2
      done
      exit 1
      ;;
  esac
}

# ── install or replace a directory, using sudo when the parent is not writable ─
safe_copy() {
  local src="$1" dest="$2" parent
  parent=$(dirname "$dest")
  if [[ -w "$parent" ]]; then
    cp -r "$src" "$dest"
  else
    sudo cp -r "$src" "$dest"
  fi
}

safe_remove() {
  local target="$1" parent
  parent=$(dirname "$target")
  if [[ -w "$parent" ]]; then
    rm -rf "$target"
  else
    sudo rm -rf "$target"
  fi
}

# ── uninstall ──────────────────────────────────────────────────────────────────
if [[ "${UNINSTALL:-}" == "true" ]]; then
  echo "=== Uninstalling ${PLUGIN_KEY} ==="

  resolve_plugins_dir
  TARGET="${CHOSEN_PLUGINS_DIR}/${PLUGIN_KEY}"

  if [[ -d "$TARGET" ]]; then
    safe_remove "$TARGET"
    ok "Removed ${TARGET}."
  else
    echo "Plugin directory not found at ${TARGET}. Nothing to remove."
  fi

  echo ""
  echo "=== ${PLUGIN_KEY} uninstalled ==="
  echo ""
  echo "Deactivate it in the GLPI admin UI if it still appears in the list:"
  echo "  Setup > Plugins > Bridge > Uninstall"
  exit 0
fi

# ── resolve version ────────────────────────────────────────────────────────────
echo "Fetching manifest..."
MANIFEST=$(curl -fsSL "$MANIFEST_URL")

LATEST=$(echo "$MANIFEST" | python3 -c "import sys,json; print(json.load(sys.stdin)['latest'])")
TARGET_VERSION="${VERSION:-$LATEST}"

VALID=$(echo "$MANIFEST" | python3 -c "
import sys, json
data = json.load(sys.stdin)
print('yes' if '$TARGET_VERSION' in data.get('versions', []) else 'no')
")

if [[ "$VALID" != "yes" ]]; then
  err "Version '$TARGET_VERSION' not found in manifest."
  echo "Available versions:" >&2
  echo "$MANIFEST" | python3 -c \
    "import sys,json; [print('  ', v) for v in json.load(sys.stdin).get('versions',[])]" >&2
  exit 1
fi

resolve_plugins_dir

echo "Installing ${PLUGIN_KEY} ${TARGET_VERSION}..."

# ── download zip ───────────────────────────────────────────────────────────────
ZIP_NAME="${PLUGIN_KEY}-${TARGET_VERSION}.zip"
ZIP_URL="${BASE_URL}/${TARGET_VERSION}/${ZIP_NAME}"

TMP_ZIP=$(mktemp /tmp/${PLUGIN_KEY}.XXXXXX.zip)
TMP_DIR=$(mktemp -d /tmp/${PLUGIN_KEY}.XXXXXX)
trap 'rm -f "$TMP_ZIP"; rm -rf "$TMP_DIR"' EXIT

echo "Downloading ${ZIP_URL}..."
curl -fsSL "$ZIP_URL" -o "$TMP_ZIP"

# ── extract ────────────────────────────────────────────────────────────────────
unzip -q "$TMP_ZIP" -d "$TMP_DIR"

if [[ ! -d "${TMP_DIR}/${PLUGIN_KEY}" ]]; then
  err "Expected '${PLUGIN_KEY}/' inside the zip. Archive may be corrupt."
  exit 1
fi

# ── install ────────────────────────────────────────────────────────────────────
DEST="${CHOSEN_PLUGINS_DIR}/${PLUGIN_KEY}"

if [[ -d "$DEST" ]]; then
  echo "Removing existing installation at ${DEST}..."
  safe_remove "$DEST"
fi

safe_copy "${TMP_DIR}/${PLUGIN_KEY}" "$DEST"

echo ""
ok "=== ${PLUGIN_KEY} ${TARGET_VERSION} installed ==="
echo ""
echo -e "${BOLD}  Location:${NC} ${DEST}"
echo ""
echo "Next steps:"
echo "  Option A -- GLPI admin UI:"
echo "    Setup > Plugins > Bridge > Install > Activate"
echo ""
echo "  Option B -- CLI (replace <glpi-root> with your GLPI web root):"
GLPI_ROOT=$(dirname "$CHOSEN_PLUGINS_DIR")
echo "    php ${GLPI_ROOT}/bin/console plugin:install  --username=glpi bridge"
echo "    php ${GLPI_ROOT}/bin/console plugin:activate bridge"
echo ""
echo "  Important: set up the OS cron so background migration jobs run:"
echo "    echo '* * * * * apache /usr/bin/php ${GLPI_ROOT}/front/cron.php' > /etc/cron.d/glpi"
echo ""
