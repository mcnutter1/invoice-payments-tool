#!/usr/bin/env bash
# Rebuild public/css/app.css with the Tailwind standalone CLI (no Node runtime).
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION="${TAILWIND_VERSION:-v3.4.17}"
case "$(uname -s)-$(uname -m)" in
  Darwin-arm64) ASSET=tailwindcss-macos-arm64 ;;
  Darwin-x86_64) ASSET=tailwindcss-macos-x64 ;;
  Linux-x86_64) ASSET=tailwindcss-linux-x64 ;;
  Linux-aarch64) ASSET=tailwindcss-linux-arm64 ;;
  *) echo "Unsupported platform"; exit 1 ;;
esac
BIN="bin/tailwindcss-${VERSION}"
mkdir -p bin
if [ ! -x "$BIN" ]; then
  curl -fsSL -o "$BIN" "https://github.com/tailwindlabs/tailwindcss/releases/download/${VERSION}/${ASSET}"
  chmod +x "$BIN"
fi
"$BIN" -c tailwind.config.js -i resources/css/app.css -o public/css/app.css --minify
echo "Built public/css/app.css"
