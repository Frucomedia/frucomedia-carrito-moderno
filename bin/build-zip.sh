#!/usr/bin/env bash
# Genera build/frucomedia-carrito-moderno.zip con la carpeta del plugin lista para instalar en WordPress.
set -euo pipefail
cd "$(dirname "$0")/.."
rm -rf build && mkdir -p build/frucomedia-carrito-moderno
tar --exclude='./.git' --exclude='./.github' --exclude='./build' --exclude='./bin' --exclude='./.gitignore' --exclude='./.gitattributes' -cf - . | tar -xf - -C build/frucomedia-carrito-moderno
(cd build && zip -rq frucomedia-carrito-moderno.zip frucomedia-carrito-moderno)
echo "build/frucomedia-carrito-moderno.zip"
