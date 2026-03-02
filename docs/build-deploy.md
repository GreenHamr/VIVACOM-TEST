# Build and Deploy – IP Inventory Backend

Документация за сглобка на целевия бинарен файл за Linux и Windows и за стартиране на backend-а (като обикновен процес, като systemd service под Linux и като служба под Windows).

---

## 1. Build – целеви бинарен файл

### 1.1 Linux

**Изисквания:** CMake 3.14+, C++11 компилатор (GCC/Clang), SQLite3 (системна или от `libs/sqlite`), при нужда libpq-dev за PostgreSQL.

**Бърз build (скрипт):**
```bash
./build.sh
```
Изпълнимият файл: `build/ip_inventory_backend`

**Ръчен build:**
```bash
mkdir build
cd build
cmake .. -DCMAKE_BUILD_TYPE=Release
make
```
Бинарният файл: `build/ip_inventory_backend`

**Деплой (копиране):** Може да копираш само `ip_inventory_backend` и да го поставиш в целева директория (напр. `/opt/ip-inventory/`), като на същото място сложиш `config.conf` (или задаваш конфиг чрез env). Зависимостите (libc, libpq и др.) трябва да са налични на целевата система.

### 1.2 Windows

**Изисквания:** CMake 3.14+, Visual Studio (например 2022) с C++ workload или MinGW. SQLite се взима от `libs/sqlite`; за PostgreSQL – PostgreSQL client за Windows.

**Бърз build (скрипт):**
```cmd
build.bat
```
Изпълним файл: `build\Release\ip_inventory_backend.exe`

**Ръчен build (Visual Studio):**
```cmd
mkdir build
cd build
cmake .. -G "Visual Studio 17 2022" -A x64
cmake --build . --config Release
```
Изпълним файл: `build\Release\ip_inventory_backend.exe`

**Ръчен build (MinGW):**
```cmd
mkdir build
cd build
cmake .. -G "MinGW Makefiles" -DCMAKE_BUILD_TYPE=Release -DCMAKE_C_COMPILER=gcc -DCMAKE_CXX_COMPILER=g++
cmake --build .
```
Изпълним файл: `build\ip_inventory_backend.exe`

**Деплой:** Копираш `ip_inventory_backend.exe` в целева папка (напр. `C:\ip-inventory\`) заедно с `config.conf`. При използване на PostgreSQL са нужни съответните DLL-и от PostgreSQL client или да са в PATH.

---

## 2. Стартиране като обикновен процес

### 2.1 Linux

От директорията на проекта (или оттам, където е бинарният файл и config):

```bash
# С config.conf в текущата директория
./build/ip_inventory_backend

# С явен път до config
IPINVENTORY_CONFIG=/etc/ip-inventory/config.conf ./build/ip_inventory_backend

# Без config файл – само env
IPINVENTORY_HOST=127.0.0.1 IPINVENTORY_PORT=8080 IPINVENTORY_DB_TYPE=sqlite IPINVENTORY_DB=./ip_inventory.db ./build/ip_inventory_backend
```

След старт backend-ът слуша на конфигурирания host/port (по подразбиране `127.0.0.1:8080`). За спиране: Ctrl+C.

### 2.2 Windows

От командния ред (cmd или PowerShell), от папката с exe и config:

```cmd
ip_inventory_backend.exe
```

Или с променливи на средата:

```cmd
set IPINVENTORY_CONFIG=C:\ip-inventory\config.conf
ip_inventory_backend.exe
```

Спиране: Ctrl+C в прозореца, в който работи процесът.

---

## 3. Стартиране като service/daemon (Linux)

За постоянно работещ backend под Linux препоръчително е да се ползва **systemd**.

### 3.1 Примерен systemd unit файл

Копирай и адаптирай примерния unit файл от `docs/ip_inventory_backend.service.example`:

```bash
sudo cp docs/ip_inventory_backend.service.example /etc/systemd/system/ip_inventory_backend.service
sudo systemctl daemon-reload
```

Редактирай `/etc/systemd/system/ip_inventory_backend.service`: коригирай пътищата `ExecStart`, `WorkingDirectory` и при нужда `Environment` (config файл, порт, БД).

### 3.2 Управление на услугата

```bash
# Стартиране
sudo systemctl start ip_inventory_backend

# Спиране
sudo systemctl stop ip_inventory_backend

# Рестарт
sudo systemctl restart ip_inventory_backend

# Статус
sudo systemctl status ip_inventory_backend

# Включване на автозареждане при boot
sudo systemctl enable ip_inventory_backend
```

### 3.3 Логове

```bash
journalctl -u ip_inventory_backend -f
```

---

## 4. Стартиране като служба (Windows)

Под Windows backend-ът може да се пусне като обикновен процес от задача по планировчик или да се регистрира като **Windows Service** чрез външен wrapper.

### 4.1 Вариант: Планировчик на задачи (Task Scheduler)

- Създай задача, която при старт на системата пуска `ip_inventory_backend.exe` с подходяща работна директория и при нужда env променливи.
- Недостатък: при падане процесът не се рестартира автоматично.

### 4.2 Вариант: Windows Service чрез NSSM

**[NSSM](https://nssm.cc/)** (Non-Sucking Service Manager) позволява да регистрираш произволен exe като Windows служба.

1. Свали NSSM и инсталирай или използвай portable версията.
2. От административен cmd:
   ```cmd
   nssm install IpInventoryBackend "C:\ip-inventory\ip_inventory_backend.exe"
   ```
3. В прозореца на NSSM задай:
   - **Path:** път до `ip_inventory_backend.exe`
   - **Startup directory:** папката с `config.conf` (напр. `C:\ip-inventory`)
   - При нужда в таб **Environment** добави променливи като `IPINVENTORY_CONFIG=C:\ip-inventory\config.conf`
4. Стартиране/спиране на службата:
   ```cmd
   nssm start IpInventoryBackend
   nssm stop IpInventoryBackend
   ```
   Или от **services.msc**: намери „IpInventoryBackend“ и управлявай услугата оттам.

### 4.3 Вариант: Собствена имплементация като Windows Service

Ако искаш нативно Windows Service, в C++ кодът трябва да имплементира SCM (Service Control Manager) API (`RegisterServiceCtrlHandler`, цикъл за съобщения и т.н.). Това е по-сложно и излиза извън текущата задача; за бърз деплой е достатъчно NSSM или планировчик.

---

## 5. Обобщение

| Платформа | Бинарен файл | Обикновен процес | Service/Daemon |
|-----------|--------------|------------------|----------------|
| **Linux** | `build/ip_inventory_backend` | `./build/ip_inventory_backend` | systemd unit (виж `docs/ip_inventory_backend.service.example`) |
| **Windows** | `build\Release\ip_inventory_backend.exe` | `ip_inventory_backend.exe` | NSSM или Task Scheduler |

Конфигурацията (host, port, БД) се задава чрез `config.conf` или променливите на средата `IPINVENTORY_*` – виж `README.md` и `config.conf.example`.
