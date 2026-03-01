#!/bin/bash
# Build на IP Inventory backend за Linux
# Изпълнение: ./build.sh
set -e
cd "$(dirname "$0")"
mkdir -p build
cd build
cmake .. -DCMAKE_BUILD_TYPE=Release
cmake --build .
echo ""
echo "Build готов. Изпълнимият файл: $(pwd)/ip_inventory_backend"
echo "Стартиране: ./ip_inventory_backend (от build/ или с път)"
