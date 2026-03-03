# IP Inventory REST API – Backend

C++ backend for IP pool management (IP address inventory) with REST API. Supports SQLite, PostgreSQL, MSSQL and Oracle via different connection types (ODBC, ADO/OLE DB, ORM).

---

## Table of Contents

1. [Code and Process Description](#1-code-and-process-description)
2. [Flowcharts](#2-flowcharts)
3. [Database](#3-database)
4. [Configuration](#4-configuration)
5. [Build Process and Scripts](#5-build-process-and-scripts) (incl. [systemd service](#56-running-as-a-systemd-service-linux))
6. [Tests](#6-tests)
7. [Apache2 Installation and Configuration](#7-apache2-installation-and-configuration)
8. [Web GUI](#8-web-gui)
9. [Job: Auto-release of expired reservations](#9-job-auto-release-of-expired-reservations)

---

## 1. Code and Process Description

### 1.1 Architecture

The project is a C++ REST API server built on:

| Component | Library | Version |
|-----------|---------|---------|
| HTTP server | cpp-httplib | v0.14.3 |
| JSON | nlohmann/json | v3.11.2 |
| Database | SQLite3 / libpq (PostgreSQL) | system or bundled |

### 1.2 Code Structure

```
src/
├── main.cpp              # Entry point, HTTP server, REST handlers
├── storage.hpp           # IStorage interface and DbConfig
├── storage_factory.cpp   # Factory for creating storage by db_type
├── storage_sqlite.cpp    # SQLite implementation
├── storage_postgresql.cpp# PostgreSQL implementation (libpq)
├── storage_odbc.cpp      # ODBC stub (MSSQL/Oracle)
├── storage_ado.cpp       # ADO/OLE DB stub (Windows)
└── ip_validation.cpp     # IPv4/IPv6 validation
```

### 1.3 Main Execution Flow

1. **Startup** – `main()` loads configuration from `config.conf` only
2. **Initialization** – `createStorage()` creates the appropriate storage backend based on `db_type` and `db_connection`
3. **Database Connection** – `storage->init()` initializes the connection (for SQLite – creates tables if missing)
4. **HTTP Server** – cpp-httplib listens on `host:port` and serves requests under `/ip-inventory/*`
5. **Request Handling** – each handler parses JSON, validates, calls storage, and returns a JSON response

### 1.4 REST API Methods

| Method | Path | Description |
|--------|------|-------------|
| POST | `/ip-inventory/ip-pool` | Add IP addresses to pool (status=free) |
| POST | `/ip-inventory/reserve-ip` | Reserve free IPs for serviceId |
| POST | `/ip-inventory/assign-ip-serviceId` | Assign reserved IPs to serviceId |
| POST | `/ip-inventory/terminate-ip-serviceId` | Release assigned IPs (return to free) |
| POST | `/ip-inventory/serviceId-change` | Transfer IPs from one serviceId to another |
| GET | `/ip-inventory/serviceId?serviceId=xxx` | Get all IPs for a given serviceId |

### 1.5 IP Address Lifecycle

```
[free] → reserve-ip → [reserved] → assign-ip-serviceId → [assigned]
                                                              ↓
[free] ← terminate-ip-serviceId ← [assigned] ← serviceId-change
```

### 1.6 Storage Layer

- **IStorage** – abstract interface with methods: `init`, `addIps`, `reserveIps`, `assignIps`, `terminateIps`, `changeServiceId`, `getByServiceId`
- **StorageFactory** – selects implementation by `db_type` (sqlite/postgresql/mssql/oracle) and `db_connection` (odbc/ado_ole_db/orm)
- **Validation** – `ip_validation.hpp`: `isValidIPv4()`, `isValidIPv6()`, `isValidIPWithType()` – used for add ip-pool and reserve-ip

---

## 2. Flowcharts

### 2.1 Application Flow

```
┌─────────────────────────────────────────────────────────────────────────┐
│                         BACKEND STARTUP                                  │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│  Load config.conf only                                                 │
│  host, port, db_type, db_connection, db_path / db_host, db_port, ...     │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│  createStorage(dbConfig) → StorageSqlite | StoragePostgresql | ...       │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│  storage->init() – DB connection, create tables (SQLite)                 │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│  HTTP Server listen(host, port)                                          │
│  Register handlers: Post/Get /ip-inventory/*                             │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
                         ┌──────────────────┐
                         │  Await requests  │◄──────────────────────┐
                         └──────────────────┘                       │
                                    │                               │
                                    ▼                               │
                    ┌───────────────────────────────┐               │
                    │  Parse JSON body               │               │
                    │  Validate fields               │               │
                    └───────────────────────────────┘               │
                                    │                               │
                                    ▼                               │
                    ┌───────────────────────────────┐               │
                    │  storage->addIps / reserveIps │               │
                    │  / assignIps / terminateIps   │               │
                    │  / changeServiceId / getBy... │               │
                    └───────────────────────────────┘               │
                                    │                               │
                                    ▼                               │
                    ┌───────────────────────────────┐               │
                    │  JSON response (200/400/500)   │───────────────┘
                    └───────────────────────────────┘
```

### 2.2 POST ip-pool Flow

```
POST /ip-inventory/ip-pool
         │
         ▼
┌─────────────────────┐     NO      ┌────────────────────────────┐
│ body.ipAddresses    │────────────►│ 400: Missing ipAddresses   │
│ is array?           │             └────────────────────────────┘
└─────────────────────┘
         │ YES
         ▼
┌─────────────────────┐     NO      ┌────────────────────────────┐
│ Each item has       │────────────►│ 400: Each item must have   │
│ ip and ipType?      │             │ ip and ipType              │
└─────────────────────┘             └────────────────────────────┘
         │ YES
         ▼
┌─────────────────────┐     NO      ┌────────────────────────────┐
│ ipType = IPv4 or    │────────────►│ 400: ipType must be        │
│ IPv6?               │             │ IPv4 or IPv6               │
└─────────────────────┘             └────────────────────────────┘
         │ YES
         ▼
┌─────────────────────┐     NO      ┌────────────────────────────┐
│ isValidIPWithType() │────────────►│ 400: Invalid IP address    │
│ for each IP?       │             └────────────────────────────┘
└─────────────────────┘
         │ YES
         ▼
┌─────────────────────┐     NO      ┌────────────────────────────┐
│ storage->addIps()   │────────────►│ 500: <DB error>            │
└─────────────────────┘             └────────────────────────────┘
         │ YES
         ▼
┌─────────────────────┐
│ 200: statusCode 0   │
│ Successful operation│
└─────────────────────┘
```

### 2.3 Storage Backend Selection (Factory)

```
                    createStorage(dbConfig)
                              │
                              ▼
              ┌───────────────────────────────┐
              │ db_type == "sqlite" ?         │──YES──► StorageSqlite
              └───────────────────────────────┘
                              │ NO
                              ▼
              ┌───────────────────────────────┐
              │ db_type == "postgresql" &&    │──YES──► StoragePostgresql
              │ IPINVENTORY_HAS_POSTGRESQL?   │        (if compiled)
              └───────────────────────────────┘
                              │ NO
                              ▼
              ┌───────────────────────────────┐
              │ db_type in (mssql, oracle) && │
              │ db_connection in (odbc, orm)? │──YES──► StorageOdbc (stub)
              └───────────────────────────────┘
                              │
              ┌───────────────┴───────────────┐
              │ db_connection == ado_ole_db?  │──YES──► StorageAdo (stub)
              └───────────────────────────────┘
                              │ NO
                              ▼
                         return nullptr
```

---

## 3. Database

### 3.1 Table `ip_pool`

| Column       | Type           | Description |
|-------------|----------------|-------------|
| `id`        | PK, auto       | Unique identifier |
| `ip`        | VARCHAR(45)    | IP address (unique) |
| `ip_type`   | VARCHAR(4)     | `'IPv4'` or `'IPv6'` |
| `status`    | VARCHAR(10)    | `'free'` \| `'reserved'` \| `'assigned'` |
| `service_id`| VARCHAR(255)   | Nullable; for reserved/assigned – serviceId |
| `reserved_at`   | TIMESTAMP  | When reserved |
| `assigned_at`   | TIMESTAMP  | When assigned |
| `created_at`    | TIMESTAMP  | When added |

### 3.2 Status and API

| API method | Action on ip_pool |
|------------|-------------------|
| POST ip-pool | INSERT with status='free' |
| POST reserve-ip | UPDATE free → reserved, service_id, reserved_at |
| POST assign-ip-serviceId | UPDATE reserved → assigned, assigned_at |
| POST terminate-ip-serviceId | UPDATE assigned → free, service_id=NULL |
| POST serviceId-change | UPDATE service_id from old to new |
| GET serviceId | SELECT by service_id |

### 3.3 Indexes

- Unique: `ip`
- `(status, ip_type)` – for fast lookup of free IPs on reserve
- `(service_id)` – for lookup by serviceId

### 3.4 SQL Creation Scripts

| DB | File |
|----|------|
| SQLite | `database/sqlite/01_create_tables.sql` – executed automatically by backend |
| PostgreSQL | `database/postgresql/01_create_tables.sql` – manual: `psql -U user -d db -f ...` |
| MSSQL | `database/mssql/01_create_tables.sql` – manual: sqlcmd or SSMS |
| Oracle | `database/oracle/01_create_tables.sql` – manual: sqlplus or SQL Developer |

### 3.5 Supported Backends

| Backend | File | Status |
|---------|------|--------|
| SQLite | storage_sqlite.cpp | ✅ Full |
| PostgreSQL | storage_postgresql.cpp | ✅ Full (with libpq-dev) |
| ODBC (MSSQL/Oracle) | storage_odbc.cpp | ⚠️ Stub |
| ADO/OLE DB (Windows) | storage_ado.cpp | ⚠️ Stub |

---

## 4. Configuration

### 4.1 config.conf File

Format: `key=value`, one per line. Lines starting with `#` are ignored.

**All configuration is read from this file only** (no environment variables).

### 4.2 Options

| Key | Description | Default |
|-----|-------------|---------|
| `host` | Bind address | 127.0.0.1 |
| `port` | Listen port | 8888 |
| `db_type` | sqlite \| postgresql \| mssql \| oracle | sqlite |
| `db_connection` | odbc \| ado_ole_db \| orm (ignored for postgresql) | odbc |
| `db_path` | Path to SQLite file | ip_inventory.db |
| `db_connection_string` | Full ODBC connection string | - |
| `db_host` | DB host | - |
| `db_port` | DB port | - |
| `db_name` | Database name | - |
| `db_user` | User | - |
| `db_password` | Password | - |

### 4.3 Configuration Examples

**SQLite (default):**
```ini
host=127.0.0.1
port=8888
db_type=sqlite
db_path=ip_inventory.db
```

**PostgreSQL:**
```ini
host=127.0.0.1
port=8888
db_type=postgresql
db_host=localhost
db_port=5432
db_name=ipinv
db_user=myuser
db_password=mypass
```

**MSSQL via ODBC:**
```ini
db_type=mssql
db_connection=odbc
db_connection_string=Driver={ODBC Driver 17 for SQL Server};Server=localhost,1433;Database=ipinv;Uid=user;Pwd=pass;
```

**Oracle via ODBC:**
```ini
db_type=oracle
db_connection=odbc
db_connection_string=Driver={Oracle in instantclient};Dbq=localhost:1521/ORCL;Uid=user;Pwd=pass;
```

Edit `config.conf` in the same directory (or where the binary is run from) to set host, port, and database.

---

## 5. Build Process and Scripts

### 5.1 Requirements

- **CMake** 3.14+
- **C++ compiler** with C++11 support (GCC, Clang, MSVC)
- **SQLite3** – system library or bundled in `libs/sqlite`
- **PostgreSQL** (optional): `libpq-dev` (Linux) or PostgreSQL client (Windows)

### 5.2 Build Scripts

Build scripts are in the project root:

**Linux** – `./build.sh`:
```bash
./build.sh
# Executable: build/ip_inventory_backend
```

**Windows** – `build.bat` (double-click or from cmd):
```cmd
build.bat
REM Executable: build\Release\ip_inventory_backend.exe
```

The Windows script uses Visual Studio 2022 by default. For MinGW or VS 2019 – edit `build.bat` and change the `-G "..."` in the cmake command.

### 5.3 Manual Build

**Linux:**
```bash
mkdir build
cd build
cmake .. -DCMAKE_BUILD_TYPE=Release
make
# Run: ./ip_inventory_backend
```

**Windows (Visual Studio):**
```cmd
mkdir build
cd build
cmake .. -G "Visual Studio 17 2022" -A x64
cmake --build . --config Release
REM Run: build\Release\ip_inventory_backend.exe
```

**Windows (MinGW):**
```cmd
mkdir build
cd build
cmake .. -G "MinGW Makefiles" -DCMAKE_BUILD_TYPE=Release -DCMAKE_C_COMPILER=gcc -DCMAKE_CXX_COMPILER=g++
cmake --build .
REM Run: build\ip_inventory_backend.exe
```

### 5.4 Dependencies

Libraries are in `libs/` in the project root:
- **cpp-httplib** v0.14.3 – `libs/httplib/httplib.h`
- **nlohmann/json** v3.11.2 – `libs/json/include/nlohmann/json.hpp`
- **SQLite3** – `libs/sqlite/` (used if system SQLite is not found)

See `libs/README.md` for sources.

### 5.5 compile_commands.json

For clangd/IDE: `CMAKE_EXPORT_COMPILE_COMMANDS=ON` is enabled. After build, `compile_commands.json` is in `build/`. Create a symlink in the root:
```bash
ln -sf build/compile_commands.json compile_commands.json
```

### 5.6 Running as a systemd service (Linux)

To run the backend as a persistent service on Linux, use **systemd**.

**1. Prepare**

- Copy the executable and config to a directory, e.g. `/opt/ip-inventory/`:
  ```bash
  sudo mkdir -p /opt/ip-inventory
  sudo cp build/ip_inventory_backend /opt/ip-inventory/
  sudo cp config.conf /opt/ip-inventory/
  ```
- (Optional) Create a dedicated user for the service:
  ```bash
  sudo useradd -r -s /bin/false ipinv
  sudo chown -R ipinv:ipinv /opt/ip-inventory
  ```

**2. systemd unit file**

Create `/etc/systemd/system/ip_inventory_backend.service` with:

```ini
[Unit]
Description=IP Inventory REST API Backend
After=network.target

[Service]
Type=simple
User=ipinv
Group=ipinv
WorkingDirectory=/opt/ip-inventory
ExecStart=/opt/ip-inventory/ip_inventory_backend
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
```

If you use a different user or path, change `User`, `Group`, `WorkingDirectory` and `ExecStart`. For a different config or port, add:
```ini
# Config is read from config.conf in WorkingDirectory
```

**3. Enable and start**

```bash
sudo systemctl daemon-reload
sudo systemctl enable ip_inventory_backend
sudo systemctl start ip_inventory_backend
```

**4. Service control**

```bash
sudo systemctl start ip_inventory_backend    # start
sudo systemctl stop ip_inventory_backend     # stop
sudo systemctl restart ip_inventory_backend  # restart
sudo systemctl status ip_inventory_backend   # status
```

**5. Logs**

```bash
journalctl -u ip_inventory_backend -f
```

---

## 6. Tests

### 6.1 PHP Test Scripts

Tests are in `tests/php/` and use cURL to call the REST API.

**Requirements:** PHP 7.4+ with **curl** extension

### 6.2 Test Configuration

File `tests/php/config.php`:
- `base_url` – default `http://127.0.0.1:8888`
- Set `api_url` in project root `config.conf` to change the API base URL.

### 6.3 Test Scripts

| Script | API method | Description |
|--------|------------|-------------|
| `test-ip-pool.php` | POST ip-pool | Add IPs to pool |
| `test-reserve-ip.php` | POST reserve-ip | Reserve for serviceId |
| `test-assign-ip.php` | POST assign-ip-serviceId | Assign reserved IPs |
| `test-terminate-ip.php` | POST terminate-ip-serviceId | Release IPs |
| `test-serviceId-change.php` | POST serviceId-change | Transfer to another serviceId |
| `test-get-serviceId.php` | GET serviceId | Lookup by serviceId |
| `run-all.php` | All | Full scenario (9 steps) |

### 6.4 Running Tests

**Before testing:** Start the backend:
```bash
./build/ip_inventory_backend
# Config is read from config.conf in the current directory
```

**All tests (full scenario):**
```bash
cd tests/php
php run-all.php
```

**Individual tests:**
```bash
php test-ip-pool.php
php test-reserve-ip.php
php test-assign-ip.php
php test-terminate-ip.php
php test-serviceId-change.php
php test-get-serviceId.php
php test-get-serviceId.php zzzppp   # with serviceId parameter
```

**With different URL:**
```bash
php run-all.php
# API base URL and path come from config.conf (api_url, api_path)
```

### 6.5 run-all.php Flow (9 steps)

1. POST ip-pool – add 3 IPs (2 IPv4, 1 IPv6)
2. POST reserve-ip – reserve for serviceId `xxxyyy`, ipType `Both`
3. GET serviceId – check before assign
4. POST assign-ip-serviceId – assign 2 of the reserved
5. GET serviceId – check after assign
6. POST serviceId-change – transfer from `xxxyyy` to `zzzppp`
7. GET serviceId – check with new serviceId
8. POST terminate-ip-serviceId – release IPs
9. GET serviceId – check after terminate (should be empty)

### 6.6 Helper Functions (api-helper.php)

- `apiRequest($method, $path, $body)` – sends HTTP request, returns `['http_code', 'body', 'raw', 'error?']`
- `printResult($name, $result)` – prints result, returns true/false for success

---

## 7. Apache2 Installation and Configuration

The backend listens locally (e.g. `127.0.0.1:8888`). Apache2 sits in front as a **reverse proxy** and forwards requests for `/ip-inventory/` to the C++ server.

### 7.1 Linux

**Install (Debian/Ubuntu):**
```bash
sudo apt update
sudo apt install apache2
```

**Enable proxy modules:**
```bash
sudo a2enmod proxy proxy_http
sudo systemctl restart apache2
```

**Virtual host configuration** – create a file (e.g. `/etc/apache2/sites-available/ip-inventory.conf`):

```apache
<VirtualHost *:80>
    ServerName api.example.com

    ProxyPass        /ip-inventory/ http://127.0.0.1:8888/ip-inventory/
    ProxyPassReverse /ip-inventory/ http://127.0.0.1:8888/ip-inventory/
    RequestHeader set X-Forwarded-Proto "http"
</VirtualHost>
```

Enable the site and reload Apache:
```bash
sudo a2ensite ip-inventory.conf
sudo systemctl reload apache2
```

**HTTPS (SSL handled by Apache):** add a virtual host for port 443 with `SSLEngine on`, `SSLCertificateFile`, `SSLCertificateKeyFile` and the same `ProxyPass`/`ProxyPassReverse`; set `RequestHeader set X-Forwarded-Proto "https"`. Example:

```apache
<VirtualHost *:443>
    ServerName api.example.com
    SSLEngine on
    SSLCertificateFile     /path/to/cert.pem
    SSLCertificateKeyFile  /path/to/key.pem
    ProxyPass        /ip-inventory/ http://127.0.0.1:8888/ip-inventory/
    ProxyPassReverse /ip-inventory/ http://127.0.0.1:8888/ip-inventory/
    RequestHeader set X-Forwarded-Proto "https"
</VirtualHost>
```

### 7.2 Windows

Install Apache HTTP Server for Windows (e.g. from [Apache Lounge](https://www.apachelounge.com/)). In the virtual host configuration:

- Enable `mod_proxy` and `mod_proxy_http` (`LoadModule` directives with paths to `modules\mod_proxy.so` etc. according to your installation).
- Add the same `ProxyPass` and `ProxyPassReverse` for `/ip-inventory/` to `http://127.0.0.1:8888/ip-inventory/`.

Paths to modules and config files follow Windows conventions (e.g. `C:\Program Files\Apache Group\Apache2\conf\`).

### 7.3 Verification

Ensure the backend is running on port 8888 (or the port set in `config.conf`). Then:

```bash
curl http://localhost/ip-inventory/serviceId?serviceId=test
```

If Apache is configured correctly, the request reaches the C++ backend and returns JSON (e.g. `{"ipAddresses":[], ...}` or an error with `statusCode`).

---

## 8. Web GUI

PHP interface for adding IP addresses to the pool via **POST /ip-inventory/ip-pool**. Files are in the `web/` directory.

**Files:** `web/index.php` – form with rows for IP and type (IPv4/IPv6); sends the request to the API. `web/config.php` – API base URL (default `http://127.0.0.1:8888`). Set `api_url` in `config.conf`.

**Requirements:** PHP 5.6+ with **curl** extension; backend must be running on the configured URL.

**Running:** With Apache – put `web/` in DocumentRoot or as a subdirectory and open `index.php`. With PHP built-in server from the project root:
```bash
php -S 0.0.0.0:9000 -t web
```
Open in browser: `http://localhost:9000/`

**Usage:** Enter one or more IP addresses and select type (IPv4/IPv6). Empty rows are ignored. Click “Add to pool” – on success a message is shown; on API error the error text is displayed.

---

## 9. Job: Auto-release of expired reservations

The script `jobs/release-expired-reservations.php` releases reserved IPs (status='reserved') whose reservation time (`reserved_at`) is older than a given number of minutes – i.e. they were not assigned via `assign-ip-serviceId` in time.

**Requirements:** PHP 5.6+ with **PDO** and **pdo_sqlite** (for SQLite) and/or **pdo_pgsql** (for PostgreSQL). Same `config.conf` as the backend .

**Running:**
```bash
# Default – reservations older than 30 minutes
php jobs/release-expired-reservations.php

# Reservations older than 60 minutes
php jobs/release-expired-reservations.php 60

# Optional: set in config.conf
# Set release_older_than_minutes in config.conf, or pass minutes as argument:
php jobs/release-expired-reservations.php 45
```

**Cron:** Example – every 15 minutes, release reservations older than 30 minutes:
```cron
*/15 * * * * php /path/to/VIVACOM-TEST/jobs/release-expired-reservations.php 30
```
Run the job from the project root (or ensure `config.conf` is in the parent directory of `jobs/` when run from cron).


