#!/usr/bin/env bash
# Branching Paths — build the frontend and place it in public/.
# Needs Node.js 18+ and npm on the build machine only.
# Usage (from anywhere): bash scripts/build.sh
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT/frontend"
npm ci --no-audit --no-fund
npm run build
# Replace previous build output, keeping api/, .htaccess and favicon.
rm -rf "$ROOT/public/assets" "$ROOT/public/index.html"
cp -R dist/. "$ROOT/public/"
echo "Frontend built into public/ (version $(cat "$ROOT/VERSION"))."
