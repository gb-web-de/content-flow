#!/usr/bin/env bash
#
# Post-start setup for the content_flow extension development instance.
#
# Modelled on the TYPO3 core "tryout" scaffold's post-start hook, which solves the
# three things that made the previous inline-hook version fail silently:
#
#   1. `set -euo pipefail` + explicit error branches, so a failure stops the run and
#      says why, instead of leaving a half-built instance behind.
#   2. An idempotency guard around `typo3 setup`. The previous version ran
#      `setup --force` on *every* `ddev start`, reconfiguring the instance each time.
#      Setup must happen once.
#   3. Credentials come from web_environment (see .ddev/config.yaml) rather than a
#      long CLI flag list.
#
set -euo pipefail

PROJECT_ROOT="/var/www/html"
cd "${PROJECT_ROOT}"

BOLD="\033[1m"; GREEN="\033[0;32m"; YELLOW="\033[0;33m"; RED="\033[0;31m"; NC="\033[0m"
info()    { echo -e "  $1"; }
success() { echo -e "  ${GREEN}✓${NC} $1"; }
warn()    { echo -e "  ${YELLOW}!${NC} $1"; }
error()   { echo -e "  ${RED}✗${NC} $1" >&2; }

echo ""
echo -e "${BOLD}Content Flow — dev instance setup${NC}"
echo "═══════════════════════════════════════"

# --- 1/4 Composer ---------------------------------------------------------
info "[1/4] composer install"
if ! composer install --no-interaction --no-progress; then
    error "composer install failed"
    error "  → most often a version conflict; check with: ddev composer why-not typo3/theme-camino"
    exit 1
fi
success "dependencies installed"

# --- 2/4 TYPO3 setup (once) ----------------------------------------------
case "${DDEV_WEBSERVER_TYPE:-nginx-fpm}" in
    apache*) SERVER_TYPE="apache" ;;
    *)       SERVER_TYPE="other" ;;
esac

if [ ! -f "${PROJECT_ROOT}/.Build/config/system/settings.php" ]; then
    info "[2/4] typo3 setup (first run, server-type=${SERVER_TYPE})"
    if ! .Build/bin/typo3 setup \
            --no-interaction \
            --force \
            --server-type="${SERVER_TYPE}" \
            --create-site="${DDEV_PRIMARY_URL:-https://content-flow.ddev.site}/"; then
        error "typo3 setup failed"
        error "  → retry manually: ddev exec .Build/bin/typo3 setup --no-interaction --force"
        exit 1
    fi
    success "TYPO3 installed"
else
    info "[2/4] TYPO3 already configured, skipping setup"
fi

# --- 3/4 Extensions -------------------------------------------------------
info "[3/4] extension setup + cache flush"
.Build/bin/typo3 extension:setup || warn "extension:setup reported warnings"
.Build/bin/typo3 cache:flush     || warn "cache:flush reported warnings"
success "extensions ready"

# --- 4/4 Demo content -----------------------------------------------------
# The Camino theme does NOT ship demo content - on a fresh install it creates a
# site and one empty page, nothing else. An empty board is impossible to judge,
# so content_flow brings its own demo command (pages + workspace + stages).
info "[4/4] demo content"
.Build/bin/typo3 contentflow:democontent || warn "demo content step reported warnings"

echo "═══════════════════════════════════════"
success "ready"
echo ""
echo -e "  ${BOLD}Backend:${NC} ${DDEV_PRIMARY_URL:-https://content-flow.ddev.site}/typo3/"
echo -e "  ${BOLD}Login:${NC}   admin / Password.1"
echo -e "  ${BOLD}Module:${NC}  Web → Content Flow (switch to the 'Editorial' workspace first)"
echo ""
