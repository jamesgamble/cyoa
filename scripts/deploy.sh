#!/usr/bin/env bash
# Branching Paths — deploy step for platforms that build from GitHub
# (Forge, Ploi, Cloudways, etc.). Run after the platform pulls the repo.
# The environment variables in docs/ENVIRONMENT.md must be set for PHP CLI too.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
bash scripts/build.sh
PHP_BIN="${PHP_BIN:-php}"
"$PHP_BIN" scripts/initialize.php
"$PHP_BIN" scripts/migrate.php
"$PHP_BIN" scripts/system-check.php
