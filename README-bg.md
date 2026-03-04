# IP Inventory REST API – Backend

C++ backend за управление на IP pool (инвентар на IP адреси) с REST API. Поддържа SQLite, PostgreSQL, MSSQL и Oracle чрез различни типове връзка (ODBC, ADO/OLE DB, ORM).

---

## Съдържание

1. [Описание на кода и процесите](#1-описание-на-кода-и-процесите)
2. [Блок-схеми](#2-блок-схеми)
3. [База данни](#3-база-данни)
4. [Конфигурация](#4-конфигурация)
5. [Build процес и скриптове](#5-build-процес-и-скриптове) (вкл. [стартиране като systemd услуга](#56-стартиране-като-systemd-услуга-linux))
6. [Тестове](#6-тестове)
7. [Инсталиране и конфигуриране на Apache2](#7-инсталиране-и-конфигуриране-на-apache2)
8. [Web GUI](#8-web-gui)
9. [Job: Автоматично освобождаване на изтекли резервации](#9-job-автоматично-освобождаване-на-изтекли-резервации)

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

1. **Стартиране** – `main()` зарежда конфигурация само от `config.conf`
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
│  Зареждане само от config.conf                                         │
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

**Всички настройки се четат само от този файл** (без променливи на средата).

### 4.2 Опции

| Ключ | Описание | По подразбиране |
|------|----------|-----------------|
| `host` | Адрес за слушане | 127.0.0.1 |
| `port` | Порт | 8888 |
| `db_type` | sqlite \| postgresql \| mssql \| oracle | sqlite |
| `db_connection` | odbc \| ado_ole_db \| orm (за postgresql се игнорира) | odbc |
| `db_path` | Път до SQLite файл | ip_inventory.db |
| `db_connection_string` | Пълен ODBC connection string | - |
| `db_host` | Хост на БД | - |
| `db_port` | Порт на БД | - |
| `db_name` | Име на база | - |
| `db_user` | Потребител | - |
| `db_password` | Парола | - |

### 4.3 Примери за конфигуриране

**SQLite (по подразбиране):**
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

Редактирай `config.conf` в същата директория (или откъдето се стартира бинарният файл), за да зададеш host, port и БД.

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

### 5.6 Стартиране като systemd услуга (Linux)

За постоянно работещ backend под Linux може да се ползва **systemd**.

**1. Подготовка**

- Копирай изпълнимия файл и конфигурацията в една директория, напр. `/opt/ip-inventory/`:
  ```bash
  sudo mkdir -p /opt/ip-inventory
  sudo cp build/ip_inventory_backend /opt/ip-inventory/
  sudo cp config.conf /opt/ip-inventory/
  ```
- (По желание) Създай отделен потребител за услугата:
  ```bash
  sudo useradd -r -s /bin/false ipinv
  sudo chown -R ipinv:ipinv /opt/ip-inventory
  ```

**2. Unit файл за systemd**

Създай файл `/etc/systemd/system/ip_inventory_backend.service` със съдържание:

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

Ако използваш друг потребител или път, промени `User`, `Group`, `WorkingDirectory` и `ExecStart`. За различен конфиг или порт може да добавиш реда:
```ini
# Конфигурацията се чете от config.conf в WorkingDirectory
```

**3. Активиране и стартиране**

```bash
sudo systemctl daemon-reload
sudo systemctl enable ip_inventory_backend
sudo systemctl start ip_inventory_backend
```

**4. Управление на услугата**

```bash
sudo systemctl start ip_inventory_backend    # стартиране
sudo systemctl stop ip_inventory_backend     # спиране
sudo systemctl restart ip_inventory_backend  # рестарт
sudo systemctl status ip_inventory_backend   # статус
```

**5. Логове**

```bash
journalctl -u ip_inventory_backend -f
```

---

## 6. Тестове

### 6.1 PHP тестови скриптове

Тестовете са в `tests/php/` и използват cURL за извикване на REST API.

**Изисквания:** PHP 7.4+ с разширение **curl**

### 6.2 Конфигурация на тестовете

Файл `tests/php/config.php`:
- `base_url` – по подразбиране `http://127.0.0.1:8888`
- Задай `api_url` в `config.conf` в корена на проекта за базов URL на API.

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
# Конфигурацията се чете от config.conf в текущата директория
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
php run-all.php
# api_url и api_path идват от config.conf
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

## 7. Инсталиране и конфигуриране на Apache2

Backend-ът слуша локално (напр. `127.0.0.1:8888`). Apache2 стои отпред като **reverse proxy** и препраща заявките за `/ip-inventory/` към C++ сървъра.

### 7.1 Linux

**Инсталиране (Debian/Ubuntu):**
```bash
sudo apt update
sudo apt install apache2
```

**Включване на модулите за прокси:**
```bash
sudo a2enmod proxy proxy_http
sudo systemctl restart apache2
```

**Конфигурация за виртуален хост** – създай файл (напр. `/etc/apache2/sites-available/ip-inventory.conf`):

```apache
<VirtualHost *:80>
    ServerName api.example.com

    ProxyPass        /ip-inventory/ http://127.0.0.1:8888/ip-inventory/
    ProxyPassReverse /ip-inventory/ http://127.0.0.1:8888/ip-inventory/
    RequestHeader set X-Forwarded-Proto "http"
</VirtualHost>
```

Активирай сайта и рестартирай Apache:
```bash
sudo a2ensite ip-inventory.conf
sudo systemctl reload apache2
```

**HTTPS (SSL от Apache):** добави виртуален хост за порт 443 с `SSLEngine on`, `SSLCertificateFile`, `SSLCertificateKeyFile` и същите `ProxyPass`/`ProxyPassReverse`; задай `RequestHeader set X-Forwarded-Proto "https"`. Пример:

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

Инсталирай Apache HTTP Server за Windows (напр. от [Apache Lounge](https://www.apachelounge.com/)). В конфигурацията на виртуален хост:

- Включи `mod_proxy` и `mod_proxy_http` (директиви `LoadModule` с пътища към `modules\mod_proxy.so` и т.н. според твоята инсталация).
- Добави същите `ProxyPass` и `ProxyPassReverse` за `/ip-inventory/` към `http://127.0.0.1:8888/ip-inventory/`.

Пътищата до модулите и до конфигурационните файлове следват Windows конвенцията (напр. `C:\Program Files\Apache Group\Apache2\conf\`).

### 7.3 Проверка

Увери се, че backend-ът работи на порт 8888 (или конфигурирания в `config.conf`). След това:

```bash
curl http://localhost/ip-inventory/serviceId?serviceId=test
```

Ако Apache е конфигуриран правилно, заявката стига до C++ backend и връща JSON (напр. `{"ipAddresses":[], ...}` или грешка с `statusCode`).

---

## 8. Web GUI

PHP интерфейс за добавяне на IP адреси в pool чрез метода **POST /ip-inventory/ip-pool**. Файловете са в директория `web/`.

**Файлове:** `web/index.php` – форма с редове за IP и тип (IPv4/IPv6); изпраща заявка към API. `web/config.php` – базов URL на API (по подразбиране `http://127.0.0.1:8888`). Задай `api_url` в `config.conf`. `web/test-api.php` – пуска всички API тестове в браузъра (същият сценарий като `tests/php/run-all.php`).

**Изисквания:** PHP 5.6+ с разширение **curl**; backend да работи на конфигурирания URL.

**Стартиране:** Чрез Apache – сложи `web/` в DocumentRoot или като поддиректория и отвори `index.php`. Чрез PHP вградения сървър от корена на проекта:
```bash
php -S 0.0.0.0:9000 -t web
```
Отвори в браузър: `http://localhost:9000/`

**Употреба:** Въведи един или повече IP адреса и избери тип (IPv4/IPv6). Празните редове се игнорират. Натисни „Добави в pool“ – при успех се показва съобщение; при грешка от API – текстът на грешката.

---

## 9. Job: Автоматично освобождаване на изтекли резервации

Скриптът `jobs/release-expired-reservations.php` освобождава резервирани IP адреси (status='reserved'), чието време на резервация (`reserved_at`) е по-старо от зададения брой минути – т.е. не са присвоени чрез `assign-ip-serviceId` в срок.

**Изисквания:** PHP 5.6+ с **PDO** и **pdo_sqlite** (за SQLite) и/или **pdo_pgsql** (за PostgreSQL). Същият `config.conf` като backend .

**Изпълнение:**
```bash
# По подразбиране – резервации по-стари от 30 минути
php jobs/release-expired-reservations.php

# Резервации по-стари от 60 минути
php jobs/release-expired-reservations.php 60

# По избор: задай в config.conf
# Задай release_older_than_minutes в config.conf или подай минути като аргумент:
php jobs/release-expired-reservations.php 45
```

**Cron:** Пример – на всеки 15 минути, освобождаване на резервации по-стари от 30 минути:
```cron
*/15 * * * * php /path/to/VIVACOM-TEST/jobs/release-expired-reservations.php 30
```
Стартирай job-а от корена на проекта (или увери се, че `config.conf` е в родителската директория на `jobs/` при пускане от cron).

