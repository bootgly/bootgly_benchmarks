#!/usr/bin/env bash
# =============================================================================
# HTTP Server CLI Benchmark — Dependency Installer
# =============================================================================
#
# Installs all dependencies needed to run the benchmark competitors.
# Run from the HTTP_Server_CLI/ directory:
#
#   bash install.sh [--all] [--roadrunner] [--workerman] [--frankenphp] [--swoole] [--wrk]
#
# Without flags, installs everything (same as --all).
# =============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ARTIFACTS_DIR="$SCRIPT_DIR/artifacts"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
DIM='\033[2m'
NC='\033[0m'

# --- Parse flags ---
INSTALL_WRK=false
INSTALL_ROADRUNNER=false
INSTALL_WORKERMAN=false
INSTALL_HYPERF=false
INSTALL_FRANKENPHP=false
INSTALL_SWOOLE=false
INSTALL_ALL=false

if [[ $# -eq 0 ]]; then
   INSTALL_ALL=true
fi

for arg in "$@"; do
   case "$arg" in
      --all)         INSTALL_ALL=true ;;
      --wrk)         INSTALL_WRK=true ;;
      --roadrunner)  INSTALL_ROADRUNNER=true ;;
      --workerman)   INSTALL_WORKERMAN=true ;;
      --hyperf)      INSTALL_HYPERF=true ;;
      --frankenphp)  INSTALL_FRANKENPHP=true ;;
      --swoole)      INSTALL_SWOOLE=true ;;
      --help|-h)
         echo "Usage: bash install.sh [--all] [--wrk] [--roadrunner] [--workerman] [--hyperf] [--frankenphp] [--swoole]"
         echo ""
         echo "Without flags, installs everything (same as --all)."
         exit 0
         ;;
      *)
         echo -e "${RED}Unknown option: $arg${NC}"
         echo "Run: bash install.sh --help"
         exit 1
         ;;
   esac
done

if $INSTALL_ALL; then
   INSTALL_WRK=true
   INSTALL_ROADRUNNER=true
   INSTALL_WORKERMAN=true
   INSTALL_HYPERF=true
   INSTALL_FRANKENPHP=true
   INSTALL_SWOOLE=true
fi

ok ()   { echo -e "  ${GREEN}✓${NC} $1"; }
warn () { echo -e "  ${YELLOW}⚠${NC} $1"; }
fail () { echo -e "  ${RED}✗${NC} $1"; }
info () { echo -e "  ${DIM}→${NC} $1"; }

echo ""
echo -e "${BOLD}${CYAN}HTTP Server CLI Benchmark — Dependency Installer${NC}"
echo ""

# =============================================================================
# Prerequisites
# =============================================================================

echo -e "${BOLD}Checking prerequisites...${NC}"

for cmd in php lsof curl nproc awk; do
   if command -v "$cmd" >/dev/null 2>&1; then
      ok "$cmd found"
   else
      fail "$cmd not found — please install it"
      exit 1
   fi
done

if command -v composer >/dev/null 2>&1; then
   ok "composer found"
else
   fail "composer not found — install it: https://getcomposer.org/download/"
   exit 1
fi

PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
ok "PHP $PHP_VERSION"

echo ""

# =============================================================================
# wrk
# =============================================================================

if $INSTALL_WRK; then
   echo -e "${BOLD}Installing wrk...${NC}"

   if command -v wrk >/dev/null 2>&1; then
      WRK_VERSION=$(wrk --version 2>&1 | head -1 || echo "unknown")
      ok "wrk already installed: $WRK_VERSION"
   else
      if command -v apt-get >/dev/null 2>&1; then
         info "Installing via apt..."
         sudo apt-get update -qq && sudo apt-get install -y -qq wrk
         if command -v wrk >/dev/null 2>&1; then
            ok "wrk installed successfully"
         else
            warn "apt install failed. Building from source..."
            info "git clone https://github.com/wg/wrk.git /tmp/wrk && cd /tmp/wrk && make && sudo cp wrk /usr/local/bin/"
            if command -v git >/dev/null 2>&1 && command -v make >/dev/null 2>&1; then
               git clone https://github.com/wg/wrk.git /tmp/wrk 2>/dev/null
               cd /tmp/wrk && make -j"$(nproc)" >/dev/null 2>&1 && sudo cp wrk /usr/local/bin/
               cd "$SCRIPT_DIR"
               ok "wrk built and installed"
            else
               fail "git or make not available. Please install wrk manually."
            fi
         fi
      else
         warn "No apt-get available. Please install wrk manually:"
         info "  macOS:  brew install wrk"
         info "  source: git clone https://github.com/wg/wrk.git && cd wrk && make && sudo cp wrk /usr/local/bin/"
      fi
   fi
   echo ""
fi

# =============================================================================
# RoadRunner
# =============================================================================

if $INSTALL_ROADRUNNER; then
   echo -e "${BOLD}Installing RoadRunner...${NC}"

   cd "$ARTIFACTS_DIR/roadrunner"

   if [[ -f composer.json ]]; then
      info "Running composer install..."
      composer install --no-interaction --quiet 2>&1
      ok "Composer dependencies installed"

      if [[ -f ./vendor/bin/rr ]]; then
         info "Downloading RoadRunner binary..."
         php ./vendor/bin/rr get-binary --quiet 2>/dev/null || php ./vendor/bin/rr get-binary 2>&1
         if [[ -x ./rr ]]; then
            RR_VERSION=$(./rr -v 2>/dev/null | grep -oP 'version \K[0-9]+\.[0-9]+\.[0-9]+' || echo "unknown")
            ok "RoadRunner binary ready (v$RR_VERSION)"
         else
            warn "RoadRunner binary download may have failed — check ./rr"
         fi
      else
         fail "vendor/bin/rr not found after composer install"
      fi
   else
      fail "composer.json not found in artifacts/roadrunner/"
   fi

   cd "$SCRIPT_DIR"
   echo ""
fi

# =============================================================================
# Workerman
# =============================================================================

if $INSTALL_WORKERMAN; then
   echo -e "${BOLD}Installing Workerman...${NC}"

   cd "$ARTIFACTS_DIR/workerman"

   if [[ -f composer.json ]]; then
      info "Running composer install..."
      composer install --no-interaction --quiet 2>&1
      ok "Workerman dependencies installed"

      WM_VERSION=$(grep -A1 '"name": "workerman/workerman"' composer.lock 2>/dev/null \
         | grep '"version"' | grep -oP '"\Kv?[0-9][^"]+' || echo "unknown")
      ok "Workerman $WM_VERSION ready"
   else
      fail "composer.json not found in artifacts/workerman/"
   fi

   cd "$SCRIPT_DIR"
   echo ""
fi

# =============================================================================
# Hyperf
# =============================================================================

if $INSTALL_HYPERF; then
   echo -e "${BOLD}Installing Hyperf...${NC}"

   cd "$ARTIFACTS_DIR/hyperf"

   if [[ -f composer.json ]]; then
      info "Running composer install..."
      composer install --no-interaction --quiet 2>&1
      ok "Hyperf dependencies installed"

      HF_VERSION=$(grep -A5 '"name": "hyperf/framework"' composer.lock 2>/dev/null \
         | grep '"version"' | grep -oP '"\Kv?[0-9][^"]+' || echo "unknown")
      ok "Hyperf $HF_VERSION ready"

      if php -m 2>/dev/null | grep -qi swoole; then
         ok "Swoole extension detected (required by Hyperf)"
      else
         warn "Swoole extension not loaded — Hyperf requires it."
         info "Install Swoole first: bash install.sh --swoole"
      fi

      # Check swoole.use_shortname
      SHORTNAME=$(php -r "echo ini_get('swoole.use_shortname');" 2>/dev/null || echo "")
      if [[ "$SHORTNAME" == "" || "$SHORTNAME" == "Off" || "$SHORTNAME" == "0" || "$SHORTNAME" == "off" ]]; then
         ok "swoole.use_shortname is Off (required by Hyperf)"
      else
         warn "swoole.use_shortname is On — Hyperf requires it to be Off"
         info "Add 'swoole.use_shortname=Off' to your php.ini"
      fi
   else
      fail "composer.json not found in artifacts/hyperf/"
   fi

   cd "$SCRIPT_DIR"
   echo ""
fi

# =============================================================================
# FrankenPHP
# =============================================================================

if $INSTALL_FRANKENPHP; then
   echo -e "${BOLD}Checking FrankenPHP...${NC}"

   if command -v frankenphp >/dev/null 2>&1; then
      FP_VERSION=$(frankenphp --version 2>/dev/null | grep -oP 'v[0-9]+\.[0-9]+\.[0-9]+' | head -1 || echo "unknown")
      ok "FrankenPHP already installed: $FP_VERSION"
   else
      warn "FrankenPHP not found. Install manually:"
      echo ""
      info "Option 1 — Static binary (recommended for benchmarks):"
      info "  curl -fsSL https://github.com/dunglas/frankenphp/releases/latest/download/frankenphp-linux-x86_64 -o /usr/local/bin/frankenphp"
      info "  chmod +x /usr/local/bin/frankenphp"
      echo ""
      info "Option 2 — Docker:"
      info "  docker pull dunglas/frankenphp"
      echo ""
      info "See: https://frankenphp.dev/docs/install"
   fi
   echo ""
fi

# =============================================================================
# Swoole
# =============================================================================

if $INSTALL_SWOOLE; then
   echo -e "${BOLD}Checking Swoole...${NC}"

   if php -m 2>/dev/null | grep -qi swoole; then
      SWOOLE_VERSION=$(php -r 'echo swoole_version();' 2>/dev/null || echo "unknown")
      ok "Swoole extension loaded: v$SWOOLE_VERSION"
   else
      warn "Swoole extension not loaded. Install manually:"
      echo ""
      info "Option 1 — PECL:"
      info "  pecl install swoole"
      info "  echo 'extension=swoole.so' >> \$(php -i | grep 'Loaded Configuration File' | awk '{print \$NF}')"
      echo ""
      info "Option 2 — Build from source (>= v6.0):"
      info "  git clone https://github.com/swoole/swoole-src.git"
      info "  cd swoole-src && phpize && ./configure && make -j\$(nproc) && sudo make install"
      echo ""
      info "See: https://wiki.swoole.com/en/#/environment"
   fi
   echo ""
fi

# =============================================================================
# Summary
# =============================================================================

echo -e "${BOLD}${GREEN}Done!${NC}"
echo ""
echo -e "${DIM}Run benchmarks with:${NC}"
echo -e "  cd $(dirname "$SCRIPT_DIR") && ./bootgly benchmark HTTP_Server_CLI --competitors=bootgly"
echo ""
