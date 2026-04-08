#!/usr/bin/env bash
set -euo pipefail

# ── Xentara TUI Installer ────────────────────────────────────────────────────
# Installs xentara-tui.php as a system command 'xentara-tui'
#
# Usage:
#   ./install.sh              # installs to ~/.local/bin (user)
#   sudo ./install.sh         # installs to /usr/local/bin (system-wide)

APP_NAME="xentara-tui"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
SOURCE="${SCRIPT_DIR}/xentara-tui.php"

# Colors
C_RESET='\033[0m'
C_BOLD='\033[1m'
C_RED='\033[31m'
C_GREEN='\033[32m'
C_CYAN='\033[36m'
C_YELLOW='\033[33m'

info()  { echo -e "${C_CYAN}${C_BOLD}[INFO]${C_RESET}  $*"; }
ok()    { echo -e "${C_GREEN}${C_BOLD}[OK]${C_RESET}    $*"; }
warn()  { echo -e "${C_YELLOW}${C_BOLD}[WARN]${C_RESET}  $*"; }
fail()  { echo -e "${C_RED}${C_BOLD}[FAIL]${C_RESET}  $*"; exit 1; }

echo -e "${C_BOLD}${C_CYAN}"
echo "  ⚡ Xentara TUI Installer"
echo -e "  ========================${C_RESET}"
echo ""

# ── Check source file exists ─────────────────────────────────────────────────
[[ -f "$SOURCE" ]] || fail "Cannot find ${SOURCE}"

# ── Check PHP ─────────────────────────────────────────────────────────────────
info "Checking PHP..."
if ! command -v php &>/dev/null; then
    fail "PHP is not installed. Install PHP 8.1+ with: sudo apt install php-cli php-mbstring"
fi

PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
PHP_MAJOR=$(php -r 'echo PHP_MAJOR_VERSION;')
PHP_MINOR=$(php -r 'echo PHP_MINOR_VERSION;')

if (( PHP_MAJOR < 8 || (PHP_MAJOR == 8 && PHP_MINOR < 1) )); then
    fail "PHP ${PHP_VER} found, but 8.1+ is required."
fi
ok "PHP ${PHP_VER}"

# ── Check required extensions ─────────────────────────────────────────────────
MISSING_EXT=()
for ext in sockets openssl mbstring; do
    if php -m 2>/dev/null | grep -qi "^${ext}$"; then
        ok "Extension: ${ext}"
    else
        MISSING_EXT+=("$ext")
        warn "Missing extension: ${ext}"
    fi
done

if (( ${#MISSING_EXT[@]} > 0 )); then
    echo ""
    warn "Install missing extensions with:"
    echo "  sudo apt install $(printf "php-${PHP_VER}-%s " "${MISSING_EXT[@]}")"
    echo "  or: sudo apt install $(printf "php-%s " "${MISSING_EXT[@]}")"
    echo ""
    fail "Cannot continue without required extensions."
fi

# ── Determine install directory ───────────────────────────────────────────────
if [[ $EUID -eq 0 ]]; then
    INSTALL_DIR="/usr/local/bin"
else
    INSTALL_DIR="${HOME}/.local/bin"
    mkdir -p "$INSTALL_DIR"
fi

DEST="${INSTALL_DIR}/${APP_NAME}"

info "Installing to ${DEST}..."
cp "$SOURCE" "$DEST"
chmod +x "$DEST"
ok "Installed: ${DEST}"

# ── Check PATH ────────────────────────────────────────────────────────────────
if ! echo "$PATH" | tr ':' '\n' | grep -qx "$INSTALL_DIR"; then
    echo ""
    warn "${INSTALL_DIR} is not in your PATH."
    echo "  Add it with:"
    echo ""
    echo "    echo 'export PATH=\"${INSTALL_DIR}:\$PATH\"' >> ~/.bashrc && source ~/.bashrc"
    echo ""
fi

# ── Done ──────────────────────────────────────────────────────────────────────
echo ""
echo -e "${C_GREEN}${C_BOLD}Installation complete!${C_RESET}"
echo ""
echo -e "  Run:  ${C_BOLD}${APP_NAME}${C_RESET}                           (first run will prompt for credentials)"
echo -e "  Or:   ${C_BOLD}${APP_NAME} --host h --port p --user u --pass p${C_RESET}"
echo -e "  Reset:${C_BOLD} ${APP_NAME} --reset-config${C_RESET}             (clear saved credentials)"
echo ""
echo "  Config: ~/.config/xentara-tui/config.json"
echo ""
