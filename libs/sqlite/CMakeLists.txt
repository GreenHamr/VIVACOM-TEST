# SQLite3 amalgamation – bundled за IP Inventory
# Използва се когато find_package(SQLite3) не намира системна библиотека
cmake_minimum_required(VERSION 3.8)
add_library(SQLite3 STATIC sqlite3.c)
target_include_directories(SQLite3 PUBLIC ${CMAKE_CURRENT_SOURCE_DIR})
target_compile_definitions(SQLite3 PUBLIC
    SQLITE_ENABLE_MATH_FUNCTIONS
    SQLITE_OMIT_DECLTYPE
)
