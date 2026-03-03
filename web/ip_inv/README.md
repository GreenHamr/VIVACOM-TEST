# IP Inventory REST API (PHP, PostgreSQL only)

Same REST API as the C++ backend, implemented in PHP and using **PostgreSQL only**.

## Requirements

- PHP 5.6+ with PDO and **pdo_pgsql**
- Apache with `mod_rewrite` (or configure your web server to route requests to `index.php`)
- PostgreSQL database

## Configuration

- **Configuration:** all values from `config.conf` in project root only (no environment variables). Keys: `db_host`, `db_port`, `db_name`, `db_user`, `db_password`, and **`api_path`** (path prefix for routes, default `ip-inventory`; can be e.g. `ip_inv`).
- **Config file**: project root `config.conf` or `ip_inv/config.conf` (when `ip_inv` is under document root). Same keys as the C++ backend: `db_host`, `db_port`, `db_name`, `db_user`, `db_password`.

Only PostgreSQL is used; `db_type` is ignored.

## URL base

Document root of the server is `web/`; the API is in the `ip_inv` subdirectory, so the public URL is **`http://<server>/ip_inv/`**.

**With mod_rewrite** (`.htaccess` applied): use the directory as base URL:

- `api_url=http://<server>/ip_inv`
- Requests: `http://<server>/ip_inv/ip-inventory/ip-pool`, etc.

**Without mod_rewrite** (rewrite not working or `AllowOverride None`): point directly to `index.php` in `config.conf`:

- `api_url=http://<server>/ip_inv/index.php`
- The PHP tests and any client will then use the query form `?__path=ip-inventory/...`, which does not require rewrite.

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

- If **rewrite works**: ensure `AllowOverride` allows `.htaccess` so the rewrite rule sends non-file requests to `index.php`.
- If **rewrite does not work**: set `api_url` in `config.conf` to the full URL of `index.php` (e.g. `http://<server>/ip_inv/index.php`). The tests will then call the API with `?__path=ip-inventory/...` and no rewrite is needed.

## Table schema

On first run, the app creates `ip_pool` (same schema as the C++ PostgreSQL backend) in the configured database.
