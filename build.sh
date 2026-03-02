#!/bin/bash
# Build на IP Inventory backend за Linux
# Изпълнение: ./build.sh
set -e
cd "$(dirname "$0")"

# Убеди се, че C++ компилаторът е намерен (CMake понякога не открива g++ автоматично)
if [ -z "$CXX" ]; then
  if command -v g++ >/dev/null 2>&1; then
    export CXX=g++
  elif command -v clang++ >/dev/null 2>&1; then
    export CXX=clang++
  else
    echo "Грешка: не е намерен C++ компилатор (g++ или clang++)."
    echo "Инсталирай го, напр.:"
    echo "  Debian/Ubuntu: sudo apt install build-essential"
    echo "  RHEL/Fedora:   sudo dnf install gcc-c++"
    exit 1
  fi
fi

mkdir -p build
cd build
cmake .. -DCMAKE_BUILD_TYPE=Release -DCMAKE_CXX_COMPILER="${CXX}"
cmake --build .
echo ""
echo "Build готов. Изпълнимият файл: $(pwd)/ip_inventory_backend"
echo "Стартиране: ./ip_inventory_backend (от build/ или с път)"
