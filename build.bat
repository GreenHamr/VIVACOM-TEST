@echo off
REM Build на IP Inventory backend за Windows
REM Изпълнение: build.bat
cd /d "%~dp0"
if not exist build mkdir build
cd build

REM Избор на генератор (промени при нужда):
REM - Visual Studio 2022: -G "Visual Studio 17 2022" -A x64
REM - Visual Studio 2019: -G "Visual Studio 16 2019" -A x64
REM - MinGW: -G "MinGW Makefiles" -DCMAKE_BUILD_TYPE=Release

cmake .. -G "Visual Studio 17 2022" -A x64
if errorlevel 1 (
    echo CMake configure failed.
    pause
    exit /b 1
)
cmake --build . --config Release
if errorlevel 1 (
    echo Build failed.
    pause
    exit /b 1
)

echo.
echo Build готов. Изпълнимият файл: build\Release\ip_inventory_backend.exe
pause
