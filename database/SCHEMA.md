# Схема на базата данни – IP Inventory

Една таблица съдържа IP pool и състоянието на всеки адрес (свободен / резервиран / присвоен).

---

## Таблица `ip_pool`

Храни всички IP адреси в инвентара и текущия им статус.

| Колона       | Тип           | Описание |
|-------------|----------------|----------|
| `id`        | PK, auto       | Уникален идентификатор на реда |
| `ip`        | VARCHAR(45)    | IP адрес (IPv4 или IPv6); уникален |
| `ip_type`   | VARCHAR(4)     | `'IPv4'` или `'IPv6'` |
| `status`    | VARCHAR(10)    | `'free'` \| `'reserved'` \| `'assigned'` |
| `service_id`| VARCHAR(255)   | Нуллабелно; при `reserved`/`assigned` – за кой serviceId е |
| `reserved_at`   | TIMESTAMP  | Нуллабелно; кога е резервиран (за job за auto-release) |
| `assigned_at`   | TIMESTAMP  | Нуллабелно; кога е присвоен |
| `created_at`   | TIMESTAMP  | Кога е добавен в pool-а |

### Статуси

- **free** – адресът е в pool-а и не е свързан с serviceId.
- **reserved** – резервиран за даден `serviceId`; очаква `assign-ip-serviceId` (или auto-release след време).
- **assigned** – присвоен на `serviceId` след успешен `assign-ip-serviceId`.

### Връзка с API

| API метод | Действие върху `ip_pool` |
|-----------|---------------------------|
| POST ip-pool | INSERT нови редове с `status='free'`, `ip`, `ip_type` |
| POST reserve-ip | UPDATE на свободни IP → `status='reserved'`, `service_id`, `reserved_at` |
| POST assign-ip-serviceId | UPDATE на резервирани за този serviceId → `status='assigned'`, `assigned_at` |
| POST terminate-ip-serviceId | UPDATE на присвоени за serviceId → `status='free'`, `service_id=NULL`, `reserved_at`/`assigned_at=NULL` |
| POST serviceId-change | UPDATE на всички с `service_id=serviceIdOld` → `service_id=serviceId` |
| GET serviceId?serviceId= | SELECT по `service_id` и `status IN ('reserved','assigned')` |

---

## Индекси

| Индекс | Колони | Цел |
|--------|--------|-----|
| Уникален | `ip` | Без дублирани IP в pool |
| Обикновен | `(status, ip_type)` | Бързо намиране на свободни IP при reserve-ip |
| Обикновен | `(service_id)` | Търсене по serviceId (GET, terminate, serviceId-change) |

---

## Бележки за трите БД

- **PostgreSQL**: може да се използва тип `INET` за `ip`; за максимална съвместимост тук е използван `VARCHAR(45)`.
- **MSSQL**: `VARCHAR(45)`, `NVARCHAR` при нужда от Unicode за `service_id`.
- **Oracle**: `VARCHAR2(45)` за `ip`, `VARCHAR2(10)` за `ip_type`/`status`; `DATE` или `TIMESTAMP` за времена.

Скриптовете за създаване на таблиците са в папките `postgresql/`, `mssql/`, `oracle/`. За SQLite таблицата се създава автоматично от backend при стартиране.

---

## Избор на БД и тип връзка (config.conf)

Backend-ът поддържа трите БД и трите типа връзка, конфигурирани от **config.conf** (или env):

- **db_type**: `sqlite` | `postgresql` | `mssql` | `oracle`
- **db_connection**: `odbc` | `ado_ole_db` | `orm` (за postgresql/mssql/oracle)
- За sqlite: **db_path**. За останалите: **db_connection_string** или **db_host**, **db_port**, **db_name**, **db_user**, **db_password**

Виж `config.conf.example` в корена на проекта и `README-backend.md`.
