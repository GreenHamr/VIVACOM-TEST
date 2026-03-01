# IP Inventory REST API – Backend

C++ backend за управление на IP pool (инвентар на IP адреси) с REST API. Поддържа SQLite, PostgreSQL, MSSQL и Oracle чрез различни типове връзка (ODBC, ADO/OLE DB, ORM).

---

## Съдържание

1. [Описание на кода и процесите](#1-описание-на-кода-и-процесите)
2. [Блок-схеми](#2-блок-схеми)
3. [База данни](#3-база-данни)
4. [Конфигурация](#4-конфигурация)
5. [Build процес и скриптове](#5-build-процес-и-скриптове)
6. [Тестове](#6-тестове)

---

## 1. Описание на кода и процесите

### 1.1 Архитектура

Проектът е C++ REST API сървър, изграден върху:

| Компонент | Библиотека | Версия |
|-----------|------------|--------|
| HTTP сървър | cpp-httplib | v0.14.3 |
| JSON | nlohmann/json | v3.11.2 |
| База данни | SQLite3 / libpq (PostgreSQL) | системна или FetchContent |

### 1.2 Структура на кода

```
src/
├── main.cpp              # Точка на влизане, HTTP сървър, REST handlers
├── storage.hpp           # Интерфейс IStorage и DbConfig
├── storage_factory.cpp   # Фабрика за създаване на storage по db_type
├── storage_sqlite.cpp    # Имплементация за SQLite
├── storage_postgresql.cpp# Имплементация за PostgreSQL (libpq)
├── storage_odbc.cpp      # Stub за ODBC (MSSQL/Oracle)
├── storage_ado.cpp       # Stub за ADO/OLE DB (Windows)
└── ip_validation.cpp     # Валидация на IPv4/IPv6
```

### 1.3 Основен поток на изпълнение

1. **Стартиране** – `main()` зарежда конфигурация от `config.conf` или env променливи
2. **Инициализация** – `createStorage()` създава подходящ storage backend според `db_type` и `db_connection`
3. **Подключване към БД** – `storage->init()` инициализира връзката (при SQLite – създава таблиците ако липсват)
4. **HTTP сървър** – cpp-httplib слуша на `host:port` и обслужва заявки под `/ip-inventory/*`
5. **Обработка на заявки** – всеки handler парсва JSON, валидира, извиква storage и връща JSON отговор

### 1.4 REST API методи

| Метод | Път | Описание |
|-------|-----|----------|
| POST | `/ip-inventory/ip-pool` | Добавя IP адреси в pool (status=free) |
| POST | `/ip-inventory/reserve-ip` | Резервира свободни IP за serviceId |
| POST | `/ip-inventory/assign-ip-serviceId` | Присвоява резервираните IP на serviceId |
| POST | `/ip-inventory/terminate-ip-serviceId` | Освобождава присвоени IP (връща ги в free) |
| POST | `/ip-inventory/serviceId-change` | Прехвърля IP от един serviceId към друг |
| GET | `/ip-inventory/serviceId?serviceId=xxx` | Връща всички IP за даден serviceId |

### 1.5 Жизнен цикъл на IP адрес

```
[free] → reserve-ip → [reserved] → assign-ip-serviceId → [assigned]
                                                              ↓
[free] ← terminate-ip-serviceId ← [assigned] ← serviceId-change
```

### 1.6 Storage слой

- **IStorage** – абстрактен интерфейс с методи: `init`, `addIps`, `reserveIps`, `assignIps`, `terminateIps`, `changeServiceId`, `getByServiceId`
- **StorageFactory** – избира имплементация по `db_type` (sqlite/postgresql/mssql/oracle) и `db_connection` (odbc/ado_ole_db/orm)
- **Валидация** – `ip_validation.hpp`: `isValidIPv4()`, `isValidIPv6()`, `isValidIPWithType()` – използва се при add ip-pool и reserve-ip

---

## 2. Блок-схеми

### 2.1 Общ поток на приложението

```
┌─────────────────────────────────────────────────────────────────────────┐
│                         СТАРТИРАНЕ НА BACKEND                           │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│  Зареждане на config.conf (или env IPINVENTORY_*)                        │
│  host, port, db_type, db_connection, db_path / db_host, db_port, ...    │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│  createStorage(dbConfig) → StorageSqlite | StoragePostgresql | ...       │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│  storage->init() – връзка към БД, създаване на таблици (SQLite)          │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│  HTTP Server listen(host, port)                                          │
│  Регистрация на handlers: Post/Get /ip-inventory/*                       │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
                         ┌──────────────────┐
                         │  Очаква заявки   │◄──────────────────────┐
                         └──────────────────┘                       │
                                    │                               │
                                    ▼                               │
                    ┌───────────────────────────────┐               │
                    │  Парсване на JSON body         │               │
                    │  Валидация на полета           │               │
                    └───────────────────────────────┘               │
                                    │                               │
                                    ▼                               │
                    ┌───────────────────────────────┐               │
                    │  storage->addIps / reserveIps  │               │
                    │  / assignIps / terminateIps   │               │
                    │  / changeServiceId / getBy... │               │
                    └───────────────────────────────┘               │
                                    │                               │
                                    ▼                               │
                    ┌───────────────────────────────┐               │
                    │  JSON отговор (200/400/500)    │───────────────┘
                    └───────────────────────────────┘
```

### 2.2 Поток на POST ip-pool

```
POST /ip-inventory/ip-pool
         │
         ▼
┌─────────────────────┐     НЕ     ┌────────────────────────────┐
│ body.ipAddresses    │───────────►│ 400: Missing ipAddresses   │
│ е масив?            │            └────────────────────────────┘
└─────────────────────┘
         │ ДА
         ▼
┌─────────────────────┐     НЕ     ┌────────────────────────────┐
│ Всеки елемент има   │───────────►│ 400: Each item must have   │
│ ip и ipType?        │            │ ip and ipType              │
└─────────────────────┘            └────────────────────────────┘
         │ ДА
         ▼
┌─────────────────────┐     НЕ     ┌────────────────────────────┐
│ ipType = IPv4 или   │───────────►│ 400: ipType must be       │
│ IPv6?               │            │ IPv4 or IPv6               │
└─────────────────────┘            └────────────────────────────┘
         │ ДА
         ▼
┌─────────────────────┐     НЕ     ┌────────────────────────────┐
│ isValidIPWithType() │───────────►│ 400: Invalid IP address    │
│ за всеки IP?        │            └────────────────────────────┘
└─────────────────────┘
         │ ДА
         ▼
┌─────────────────────┐     НЕ     ┌────────────────────────────┐
│ storage->addIps()    │───────────►│ 500: <грешка от БД>        │
└─────────────────────┘            └────────────────────────────┘
         │ ДА
         ▼
┌─────────────────────┐
│ 200: statusCode 0   │
│ Successful operation│
└─────────────────────┘
```

### 2.3 Избор на Storage backend (Factory)

```
                    createStorage(dbConfig)
                              │
                              ▼
              ┌───────────────────────────────┐
              │ db_type == "sqlite" ?         │──ДА──► StorageSqlite
              └───────────────────────────────┘
                              │ НЕ
                              ▼
              ┌───────────────────────────────┐
              │ db_type == "postgresql" &&    │──ДА──► StoragePostgresql
              │ IPINVENTORY_HAS_POSTGRESQL?   │       (ако е компилиран)
              └───────────────────────────────┘
                              │ НЕ
                              ▼
              ┌───────────────────────────────┐
              │ db_type in (mssql, oracle) &&  │
              │ db_connection in (odbc, orm)?  │──ДА──► StorageOdbc (stub)
              └───────────────────────────────┘
                              │
              ┌───────────────┴───────────────┐
              │ db_connection == ado_ole_db?  │──ДА──► StorageAdo (stub)
              └───────────────────────────────┘
                              │ НЕ
                              ▼
                         return nullptr
```

---

## 3. База данни

### 3.1 Таблица `ip_pool`

| Колона       | Тип           | Описание |
|-------------|----------------|----------|
| `id`        | PK, auto       | Уникален идентификатор |
| `ip`        | VARCHAR(45)    | IP адрес (уникален) |
| `ip_type`   | VARCHAR(4)     | `'IPv4'` или `'IPv6'` |
| `status`    | VARCHAR(10)    | `'free'` \| `'reserved'` \| `'assigned'` |
| `service_id`| VARCHAR(255)   | Нуллабелно; при reserved/assigned – serviceId |
| `reserved_at`   | TIMESTAMP  | Кога е резервиран |
| `assigned_at`   | TIMESTAMP  | Кога е присвоен |
| `created_at`    | TIMESTAMP  | Кога е добавен |

### 3.2 Статуси и API

| API метод | Действие върху ip_pool |
|-----------|------------------------|
| POST ip-pool | INSERT с status='free' |
| POST reserve-ip | UPDATE free → reserved, service_id, reserved_at |
| POST assign-ip-serviceId | UPDATE reserved → assigned, assigned_at |
| POST terminate-ip-serviceId | UPDATE assigned → free, service_id=NULL |
| POST serviceId-change | UPDATE service_id от old на new |
| GET serviceId | SELECT по service_id |

### 3.3 Индекси

- Уникален: `ip`
- `(status, ip_type)` – за бързо намиране на свободни IP при reserve
- `(service_id)` – за търсене по serviceId

### 3.4 SQL скриптове за създаване

| БД | Файл |
|----|------|
| SQLite | `database/sqlite/01_create_tables.sql` – изпълнява се автоматично от backend |
| PostgreSQL | `database/postgresql/01_create_tables.sql` – ръчно: `psql -U user -d db -f ...` |
| MSSQL | `database/mssql/01_create_tables.sql` – ръчно: sqlcmd или SSMS |
| Oracle | `database/oracle/01_create_tables.sql` – ръчно: sqlplus или SQL Developer |

### 3.5 Поддържани backend-и

| Backend | Файл | Статус |
|---------|------|--------|
| SQLite | storage_sqlite.cpp | ✅ Пълен |
| PostgreSQL | storage_postgresql.cpp | ✅ Пълен (при libpq-dev) |
| ODBC (MSSQL/Oracle) | storage_odbc.cpp | ⚠️ Stub |
| ADO/OLE DB (Windows) | storage_ado.cpp | ⚠️ Stub |

---

## 4. Конфигурация

### 4.1 Файл config.conf

Формат: `key=value`, по един ред. Редове с `#` се игнорират.

**Приоритет:** Променливите на средата `IPINVENTORY_*` презаписват стойностите от файла.

### 4.2 Опции

| Ключ | Описание | По подразбиране |
|------|----------|-----------------|
| `host` | Адрес за слушане | 127.0.0.1 |
| `port` | Порт | 8080 |
| `db_type` | sqlite \| postgresql \| mssql \| oracle | sqlite |
| `db_connection` | odbc \| ado_ole_db \| orm (за postgresql се игнорира) | odbc |
| `db_path` | Път до SQLite файл | ip_inventory.db |
| `db_connection_string` | Пълен ODBC connection string | - |
| `db_host` | Хост на БД | - |
| `db_port` | Порт на БД | - |
| `db_name` | Име на база | - |
| `db_user` | Потребител | - |
| `db_password` | Парола | - |

### 4.3 Променливи на средата

| Променлива | Презаписва |
|------------|------------|
| `IPINVENTORY_CONFIG` | Път до config файл |
| `IPINVENTORY_HOST` | host |
| `IPINVENTORY_PORT` | port |
| `IPINVENTORY_DB_TYPE` | db_type |
| `IPINVENTORY_DB_CONNECTION` | db_connection |
| `IPINVENTORY_DB` | db_path |

### 4.4 Примери за конфигуриране

**SQLite (по подразбиране):**
```ini
host=127.0.0.1
port=8080
db_type=sqlite
db_path=ip_inventory.db
```

**PostgreSQL:**
```ini
host=127.0.0.1
port=8080
db_type=postgresql
db_host=localhost
db_port=5432
db_name=ipinv
db_user=myuser
db_password=mypass
```

**MSSQL чрез ODBC:**
```ini
db_type=mssql
db_connection=odbc
db_connection_string=Driver={ODBC Driver 17 for SQL Server};Server=localhost,1433;Database=ipinv;Uid=user;Pwd=pass;
```

**Oracle чрез ODBC:**
```ini
db_type=oracle
db_connection=odbc
db_connection_string=Driver={Oracle in instantclient};Dbq=localhost:1521/ORCL;Uid=user;Pwd=pass;
```

**Чрез env (без config файл):**
```bash
export IPINVENTORY_HOST=0.0.0.0
export IPINVENTORY_PORT=8888
export IPINVENTORY_DB_TYPE=sqlite
export IPINVENTORY_DB=/var/lib/ipinv/ip_inventory.db
./ip_inventory_backend
```

---

## 5. Build процес и скриптове

### 5.1 Изисквания

- **CMake** 3.14+
- **C++ компилатор** с поддръжка на C++11 (GCC, Clang, MSVC)
- **SQLite3** – системна библиотека или автоматично изтегляне (FetchContent)
- **PostgreSQL** (опционално): `libpq-dev` (Linux) или PostgreSQL client (Windows)

### 5.2 Build скриптове

В корена на проекта са създадени скриптове за бърз build:

**Linux** – `./build.sh`:
```bash
./build.sh
# Изпълним файл: build/ip_inventory_backend
```

**Windows** – `build.bat` (двоен клик или от cmd):
```cmd
build.bat
REM Изпълним файл: build\Release\ip_inventory_backend.exe
```

За Windows скриптът използва Visual Studio 2022 по подразбиране. За MinGW или VS 2019 – редактирай `build.bat` и промени `-G "..."` в cmake командата.

### 5.3 Ръчен build процес

**Linux:**
```bash
mkdir build
cd build
cmake .. -DCMAKE_BUILD_TYPE=Release
make
# Изпълним: ./ip_inventory_backend
```

**Windows (Visual Studio):**
```cmd
mkdir build
cd build
cmake .. -G "Visual Studio 17 2022" -A x64
cmake --build . --config Release
REM Изпълним: build\Release\ip_inventory_backend.exe
```

**Windows (MinGW):**
```cmd
mkdir build
cd build
cmake .. -G "MinGW Makefiles" -DCMAKE_BUILD_TYPE=Release -DCMAKE_C_COMPILER=gcc -DCMAKE_CXX_COMPILER=g++
cmake --build .
REM Изпълним: build\ip_inventory_backend.exe
```

### 5.4 Зависимости

Библиотеките са в `libs/` в корена на проекта:
- **cpp-httplib** v0.14.3 – `libs/httplib/httplib.h`
- **nlohmann/json** v3.11.2 – `libs/json/include/nlohmann/json.hpp`
- **SQLite3** – `libs/sqlite/` (използва се ако системен SQLite не е намерен)

Виж `libs/README.md` за източниците.

### 5.5 compile_commands.json

За clangd/IDE: `CMAKE_EXPORT_COMPILE_COMMANDS=ON` е включено. След build, `compile_commands.json` е в `build/`. Създай симлинк в корена:
```bash
ln -sf build/compile_commands.json compile_commands.json
```

---

## 6. Тестове

### 6.1 PHP тестови скриптове

Тестовете са в `tests/php/` и използват cURL за извикване на REST API.

**Изисквания:** PHP 7.4+ с разширение **curl**

### 6.2 Конфигурация на тестовете

Файл `tests/php/config.php`:
- `base_url` – по подразбиране `http://127.0.0.1:8888`
- Промяна чрез env: `export IPINVENTORY_API_URL=http://localhost:8080`

### 6.3 Тестови скриптове

| Скрипт | API метод | Описание |
|--------|-----------|----------|
| `test-ip-pool.php` | POST ip-pool | Добавяне на IP в pool |
| `test-reserve-ip.php` | POST reserve-ip | Резервиране за serviceId |
| `test-assign-ip.php` | POST assign-ip-serviceId | Присвояване на резервирани IP |
| `test-terminate-ip.php` | POST terminate-ip-serviceId | Освобождаване на IP |
| `test-serviceId-change.php` | POST serviceId-change | Прехвърляне към друг serviceId |
| `test-get-serviceId.php` | GET serviceId | Проверка по serviceId |
| `run-all.php` | Всички | Пълен сценарий (9 стъпки) |

### 6.4 Изпълнение

**Преди тестване:** Стартирай backend:
```bash
./build/ip_inventory_backend
# или с config: IPINVENTORY_CONFIG=config.conf ./build/ip_inventory_backend
```

**Всички тестове (пълен сценарий):**
```bash
cd tests/php
php run-all.php
```

**Отделни тестове:**
```bash
php test-ip-pool.php
php test-reserve-ip.php
php test-assign-ip.php
php test-terminate-ip.php
php test-serviceId-change.php
php test-get-serviceId.php
php test-get-serviceId.php zzzppp   # с параметър serviceId
```

**С различен URL:**
```bash
IPINVENTORY_API_URL=http://127.0.0.1:8080 php run-all.php
```

### 6.5 Поток на run-all.php (9 стъпки)

1. POST ip-pool – добавя 3 IP (2 IPv4, 1 IPv6)
2. POST reserve-ip – резервира за serviceId `xxxyyy`, ipType `Both`
3. GET serviceId – проверка преди assign
4. POST assign-ip-serviceId – присвоява 2 от резервираните
5. GET serviceId – проверка след assign
6. POST serviceId-change – прехвърля от `xxxyyy` към `zzzppp`
7. GET serviceId – проверка с новия serviceId
8. POST terminate-ip-serviceId – освобождава IP
9. GET serviceId – проверка след terminate (трябва да е празен)

### 6.6 Помощни функции (api-helper.php)

- `apiRequest($method, $path, $body)` – изпраща HTTP заявка, връща `['http_code', 'body', 'raw', 'error?']`
- `printResult($name, $result)` – отпечатва резултат, връща true/false за успех

---

## Допълнителна документация

- `README-backend.md` – кратко описание на backend
- `ToDo.md` – задачи и спецификация
- `database/SCHEMA.md` – детайлна схема на БД
- `config.conf.example` – примерен конфигурационен файл
- `tests/php/README.md` – описание на PHP тестовете
