#!/bin/bash
# Build IP Inventory backend for Linux
# Run: ./build.sh
set -e
cd "$(dirname "$0")"

# Ensure C++ compiler is found (CMake sometimes does not detect g++ automatically)
if [ -z "$CXX" ]; then
  if command -v g++ >/dev/null 2>&1; then
    export CXX=g++
  elif command -v clang++ >/dev/null 2>&1; then
    export CXX=clang++
  else
    echo "Error: C++ compiler (g++ or clang++) not found."
    echo "Install it, e.g.:"
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
echo "Build complete. Executable: $(pwd)/ip_inventory_backend"
echo "Run: ./ip_inventory_backend (from build/ or with path)"
