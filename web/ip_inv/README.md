# IP Inventory REST API (PHP, PostgreSQL only)

Same REST API as the C++ backend, implemented in PHP and using **PostgreSQL only**.

## Requirements

- PHP 5.6+ with PDO and **pdo_pgsql**
- Apache with `mod_rewrite` (or configure your web server to route requests to `index.php`)
- PostgreSQL database

## Configuration

- **Configuration:** all values from `config.conf` in project root only (no environment variables). Keys: `db_host`, `db_port`, `db_name`, `db_user`, `db_password`.
- **Config file**: project root `config.conf` or `web/ip_inv/config.conf`. Same keys as the C++ backend: `db_host`, `db_port`, `db_name`, `db_user`, `db_password`.

Only PostgreSQL is used; `db_type` is ignored.

## URL base

If the app is under the document root at `web/ip_inv/`, the API base URL is:

- `http://your-host/web/ip_inv/ip-inventory/...`

Examples:

- `POST http://your-host/web/ip_inv/ip-inventory/ip-pool`
- `GET http://your-host/web/ip_inv/ip-inventory/serviceId?serviceId=xxx`

## Endpoints (same as C++ API)

| Method | Path | Description |
|--------|------|-------------|
| POST   | ip-inventory/ip-pool | Add IPs to pool |
| POST   | ip-inventory/reserve-ip | Reserve IPs for serviceId |
| POST   | ip-inventory/assign-ip-serviceId | Assign reserved IPs |
| POST   | ip-inventory/terminate-ip-serviceId | Terminate assigned IPs |
| POST   | ip-inventory/serviceId-change | Change serviceId (old → new) |
| GET    | ip-inventory/serviceId?serviceId=xxx | Get IPs by serviceId |

Request/response JSON format matches the C++ backend and OpenAPI spec.

## Apache

Ensure `AllowOverride` allows `.htaccess` so the rewrite rule sends all non-file requests to `index.php`.

## Table schema

On first run, the app creates `ip_pool` (same schema as the C++ PostgreSQL backend) in the configured database.
