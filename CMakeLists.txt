cmake_minimum_required(VERSION 3.14)

# IP Inventory е за host (Linux/Windows), не за embedded ARM – при arm-none-eabi конфигурацията ще се провали
if((DEFINED CMAKE_CXX_COMPILER AND CMAKE_CXX_COMPILER MATCHES "arm-none-eabi") OR
   (DEFINED CMAKE_C_COMPILER AND CMAKE_C_COMPILER MATCHES "arm-none-eabi"))
  message(FATAL_ERROR
    "This project is for host (Linux/Windows), not embedded ARM.\n"
    "Delete the build folder, then use the host compiler, e.g.:\n"
    "  cmake -DCMAKE_CXX_COMPILER=g++ -DCMAKE_C_COMPILER=gcc -DCMAKE_BUILD_TYPE=Release -S . -B build\n"
    "Or run ./build.sh . In VS Code / CMake Tools: select a kit that uses g++ or clang++ for the host.")
endif()

project(ip-inventory-backend LANGUAGES CXX C DESCRIPTION "IP Inventory REST API backend (C++)")

set(CMAKE_CXX_STANDARD 11)
set(CMAKE_EXPORT_COMPILE_COMMANDS ON)

find_package(Threads REQUIRED)

# --- Локални библиотеки (libs/ в корена на проекта) ---
set(IPINVENTORY_LIBS_DIR ${CMAKE_CURRENT_SOURCE_DIR}/libs)

# cpp-httplib (header-only)
set(httplib_DIR ${IPINVENTORY_LIBS_DIR}/httplib)

# nlohmann/json (header-only)
set(json_DIR ${IPINVENTORY_LIBS_DIR}/json/include)

# SQLite3: системна библиотека или bundled (libs/sqlite)
find_package(SQLite3 QUIET)
if(NOT SQLite3_FOUND)
  message(STATUS "SQLite3 not found, using bundled amalgamation from libs/sqlite")
  add_subdirectory(${IPINVENTORY_LIBS_DIR}/sqlite ${CMAKE_BINARY_DIR}/sqlite EXCLUDE_FROM_ALL)
  set(SQLite3_LIBRARIES SQLite3)
  set(SQLite3_INCLUDE_DIRS ${IPINVENTORY_LIBS_DIR}/sqlite)
endif()

# PostgreSQL (libpq)
find_package(PostgreSQL QUIET)
if(PostgreSQL_FOUND)
  set(IPINVENTORY_HAS_POSTGRESQL 1)
else()
  find_path(PQ_INCLUDE_DIR libpq-fe.h PATH_SUFFIXES pgsql postgresql)
  find_library(PQ_LIBRARY NAMES pq libpq)
  if(PQ_INCLUDE_DIR AND PQ_LIBRARY)
    set(IPINVENTORY_HAS_POSTGRESQL 1)
    set(PostgreSQL_INCLUDE_DIRS ${PQ_INCLUDE_DIR})
    set(PostgreSQL_LIBRARIES ${PQ_LIBRARY})
  endif()
endif()

set(BACKEND_SOURCES
  src/main.cpp
  src/storage_sqlite.cpp
  src/storage_factory.cpp
  src/storage_odbc.cpp
  src/storage_ado.cpp
  src/ip_validation.cpp
)
if(IPINVENTORY_HAS_POSTGRESQL)
  list(APPEND BACKEND_SOURCES src/storage_postgresql.cpp)
  message(STATUS "PostgreSQL (libpq) found - PostgreSQL backend enabled")
else()
  message(STATUS "PostgreSQL (libpq) not found - PostgreSQL backend disabled. Install libpq-dev (Linux) or PostgreSQL client (Windows).")
endif()

add_executable(ip_inventory_backend ${BACKEND_SOURCES})
target_include_directories(ip_inventory_backend PRIVATE
  ${httplib_DIR}
  ${json_DIR}
  ${SQLite3_INCLUDE_DIRS}
  ${PostgreSQL_INCLUDE_DIRS}
)
target_link_libraries(ip_inventory_backend PRIVATE Threads::Threads)
if(SQLite3_FOUND)
  target_link_libraries(ip_inventory_backend PRIVATE SQLite::SQLite3)
else()
  target_link_libraries(ip_inventory_backend PRIVATE SQLite3)
endif()
if(IPINVENTORY_HAS_POSTGRESQL)
  target_compile_definitions(ip_inventory_backend PRIVATE IPINVENTORY_HAS_POSTGRESQL)
  target_link_libraries(ip_inventory_backend PRIVATE ${PostgreSQL_LIBRARIES})
endif()
if(WIN32)
  target_link_libraries(ip_inventory_backend PRIVATE ws2_32)
endif()
